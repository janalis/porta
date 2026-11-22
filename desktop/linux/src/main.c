/* Porta's Linux window: GTK 4 and VTE around one Porta per project. */
#include "core.h"

#include <errno.h>
#include <gio/gio.h>
#include <glib-unix.h>
#include <gtk/gtk.h>
#include <json-glib/json-glib.h>
#include <pango/pangocairo.h>
#include <signal.h>
#include <sys/ioctl.h>
#include <stdlib.h>
#include <string.h>
#include <sys/wait.h>
#include <unistd.h>
#include <vte/vte.h>

#define APP_ID "io.github.janalis.porta"
#define ANSWER_SECONDS 4
#define SHUTDOWN_SECONDS 15
#define READ_SIZE 65536

typedef struct {
    GtkApplicationWindow *window;
    VteTerminal *terminal;
    VtePty *pty;
    PortaFilter *filter;
    PortaCloseFlow flow;
    char *token;
    char *project_key;
    char *menu_json;
    char *project; /* what this window was opened on, for a restart; NULL for the picker */
    char *title;   /* the project's name, without the unsaved mark */
    gboolean ready;
    gboolean picking;   /* Porta's picker is up: a folder sent now is the project it opens */
    gboolean choosing;  /* the picker's folder dialog is open over this window */
    GPtrArray *pending; /* paths for this project, sent once Porta is ready */
    GPid pid;
    int master;
    guint reader;
    guint answer;
    glong columns;
    glong rows;
    gboolean exited;
    gboolean closed; /* the widgets are gone; only the child is still being waited for */
    int holds;       /* the window, and the child or the spawn that will become it */
} PortaWindow;

static GList *windows = NULL;
static char *menu_generation = NULL;
static PortaWindow *menu_owner = NULL;

static void show_menus(GtkApplication *application, PortaWindow *self);

static char *runtime_dir(void)
{
    g_autofree char *self = g_file_read_link("/proc/self/exe", NULL);
    g_autofree char *dir = g_path_get_dirname(self);
    return g_build_filename(dir, "runtime", NULL);
}

static char *project_key(const char *path)
{
    g_autofree char *canonical = g_canonicalize_filename(path, NULL);
    char *real = realpath(canonical, NULL);
    return NULL != real ? real : g_steal_pointer(&canonical);
}

static char *settings_path(void)
{
    return g_build_filename(g_get_user_config_dir(), "porta-desktop", "window.ini", NULL);
}

static GKeyFile *load_settings(void)
{
    GKeyFile *settings = g_key_file_new();
    g_autofree char *path = settings_path();
    g_key_file_load_from_file(settings, path, G_KEY_FILE_NONE, NULL);
    return settings;
}

static void save_settings(PortaWindow *self)
{
    g_autoptr(GKeyFile) settings = load_settings();
    g_autofree char *path = settings_path();
    g_autofree char *dir = g_path_get_dirname(path);
    int width = 0;
    int height = 0;

    gtk_window_get_default_size(GTK_WINDOW(self->window), &width, &height);
    g_key_file_set_integer(settings, "window", "width", width);
    g_key_file_set_integer(settings, "window", "height", height);
    g_key_file_set_boolean(settings, "window", "maximized", gtk_window_is_maximized(GTK_WINDOW(self->window)));
    g_key_file_set_double(settings, "window", "scale", vte_terminal_get_font_scale(self->terminal));
    g_mkdir_with_parents(dir, 0700);
    g_key_file_save_to_file(settings, path, NULL);
}

/* The child can outlive its window: whichever ends last frees what they shared. */
static void release(PortaWindow *self)
{
    if (--self->holds > 0) {
        return;
    }
    porta_filter_free(self->filter);
    g_clear_object(&self->pty);
    g_free(self->token);
    g_free(self->project_key);
    g_free(self->menu_json);
    g_free(self->project);
    g_free(self->title);
    g_ptr_array_unref(self->pending);
    g_free(self);
}

/* Before the destroy: by the time GTK announces it, the terminal is already gone. */
static void destroy_window(PortaWindow *self)
{
    save_settings(self);
    gtk_window_destroy(GTK_WINDOW(self->window));
}

/* `desktop.json`: the font the user chose, when the file says anything usable. */
static void apply_font(PortaWindow *self)
{
    const char *home = g_getenv("PORTA_HOME");
    g_autofree char *path = g_build_filename(NULL != home && '\0' != home[0] ? home : g_get_home_dir(), ".porta", "desktop.json", NULL);
    g_autoptr(JsonParser) parser = json_parser_new();
    JsonNode *root;
    JsonObject *settings;
    PangoFontDescription *font;

    if (!json_parser_load_from_file(parser, path, NULL) || NULL == (root = json_parser_get_root(parser)) || !JSON_NODE_HOLDS_OBJECT(root)) {
        return;
    }
    settings = json_node_get_object(root);
    /* As every host reads it: a value of the wrong kind, or out of range, is not set. */
    JsonNode *family = json_object_get_member(settings, "fontFamily");
    JsonNode *size = json_object_get_member(settings, "fontSize");
    const char *name = NULL != family && JSON_NODE_HOLDS_VALUE(family) && G_TYPE_STRING == json_node_get_value_type(family) ? json_node_get_string(family) : NULL;
    gboolean number = NULL != size && JSON_NODE_HOLDS_VALUE(size) && (G_TYPE_DOUBLE == json_node_get_value_type(size) || G_TYPE_INT64 == json_node_get_value_type(size));
    double points = number ? json_node_get_double(size) : 0;
    /* The family as written, taken literally: parsed from a string, "Iosevka Term Light" would
     * lose its last word to a weight, and a family ending in a number to a size. */
    font = pango_font_description_new();
    pango_font_description_set_family(font, NULL != name && '\0' != name[0] ? name : "Monospace");
    pango_font_description_set_size(font, (int) ((points >= 6 && points <= 72 ? points : 11) * PANGO_SCALE));
    vte_terminal_set_font(self->terminal, font);
    pango_font_description_free(font);
}

/* What the font dialog shows: monospaced families only, a terminal's grid needs them. */
static gboolean monospaced(gpointer item, gpointer data)
{
    (void) data;
    if (PANGO_IS_FONT_FACE(item)) {
        item = pango_font_face_get_family(PANGO_FONT_FACE(item));
    }
    return PANGO_IS_FONT_FAMILY(item) && pango_font_family_is_monospace(PANGO_FONT_FAMILY(item));
}

/* A font's size in points, as desktop.json and the font dialog count it. VTE gives its own default
 * in pixels: shown as it is, 11 points read 14.67, and Select saved that, a third larger. */
static void size_in_points(PangoFontDescription *font, GtkWidget *widget)
{
    if (!pango_font_description_get_size_is_absolute(font)) {
        return;
    }
    double dpi = NULL != widget ? pango_cairo_context_get_resolution(gtk_widget_get_pango_context(widget)) : -1;
    pango_font_description_set_size(font, (int) (pango_font_description_get_size(font) * 72.0 / (dpi > 0 ? dpi : 96.0) + 0.5));
}

/* A font chosen in the dialog: written to desktop.json (the core says how), and every window takes it
 * at its own size, the zoom of each set back. A file that cannot be written changes nothing, and says so. */
static void font_chosen(GObject *source, GAsyncResult *result, gpointer data)
{
    PortaWindow *self = data;
    PangoFontDescription *chosen = gtk_font_dialog_choose_font_finish(GTK_FONT_DIALOG(source), result, NULL);
    const char *home = g_getenv("PORTA_HOME");
    g_autofree char *folder = g_build_filename(NULL != home && '\0' != home[0] ? home : g_get_home_dir(), ".porta", NULL);
    g_autofree char *path = g_build_filename(folder, "desktop.json", NULL);
    g_autofree char *existing = NULL;
    g_autoptr(GError) error = NULL;

    if (NULL == chosen) {
        release(self);
        return; /* cancelled */
    }
    size_in_points(chosen, self->closed ? NULL : GTK_WIDGET(self->terminal));
    g_file_get_contents(path, &existing, NULL, NULL);
    g_autofree char *written = porta_settings_with_font(existing, pango_font_description_get_family(chosen),
        (double) pango_font_description_get_size(chosen) / PANGO_SCALE);
    pango_font_description_free(chosen);
    if (0 != g_mkdir_with_parents(folder, 0700) || !g_file_set_contents_full(path, written, -1, G_FILE_SET_CONTENTS_CONSISTENT, 0600, &error)) {
        if (!self->closed) {
            g_autoptr(GtkAlertDialog) alert = gtk_alert_dialog_new("The font could not be saved");
            g_autofree char *detail = g_strdup_printf("%s: %s", path, NULL != error ? error->message : g_strerror(errno));
            gtk_alert_dialog_set_detail(alert, detail);
            gtk_alert_dialog_show(alert, GTK_WINDOW(self->window));
        }
        release(self);
        return;
    }
    for (GList *entry = windows; NULL != entry; entry = entry->next) {
        PortaWindow *window = entry->data;
        vte_terminal_set_font_scale(window->terminal, 1.0);
        apply_font(window);
    }
    release(self);
}

static void choose_font(GSimpleAction *action, GVariant *parameter, gpointer data)
{
    PortaWindow *self = data;
    g_autoptr(GtkFontDialog) dialog = gtk_font_dialog_new();
    g_autoptr(GtkCustomFilter) filter = gtk_custom_filter_new(monospaced, NULL, NULL);

    (void) action;
    (void) parameter;
    gtk_font_dialog_set_title(dialog, "Font");
    gtk_font_dialog_set_filter(dialog, GTK_FILTER(filter));
    /* The dialog's own reference: the window may close while it is open. */
    self->holds++;
    PangoFontDescription *current = pango_font_description_copy(vte_terminal_get_font(self->terminal));
    size_in_points(current, GTK_WIDGET(self->terminal));
    gtk_font_dialog_choose_font(dialog, GTK_WINDOW(self->window), current, NULL, font_chosen, self);
    pango_font_description_free(current);
}

/* The keys go back to Porta when the window's menu closes: GTK would leave them on its button, and
 * after Font… or Escape the next keys would reach nothing until a click in the terminal. Not while
 * the window goes away: the menu closes then too. */
static void menu_closed(GtkPopover *popover, gpointer data)
{
    GtkWidget *terminal = data;

    (void) popover;
    if (gtk_widget_get_mapped(terminal)) {
        gtk_widget_grab_focus(terminal);
    }
}

static void write_to_porta(PortaWindow *self, const char *bytes, gssize length)
{
    gsize left = length < 0 ? strlen(bytes) : (gsize) length;

    while (left > 0 && self->master >= 0) {
        gssize written = write(self->master, bytes, left);
        if (written < 0) {
            if (EINTR == errno || EAGAIN == errno) {
                g_usleep(1000);
                continue;
            }
            return;
        }
        bytes += written;
        left -= (gsize) written;
    }
}

static void end_porta(PortaWindow *self)
{
    self->exited = TRUE;
    if (self->pid > 0) {
        kill(self->pid, SIGHUP);
    }
    destroy_window(self);
}

static gboolean answer_timed_out(gpointer data);

static void force_quit_answered(GObject *source, GAsyncResult *result, gpointer data)
{
    PortaWindow *self = data;

    /* Button 1 is "Force Quit"; anything else keeps waiting. */
    if (1 == gtk_alert_dialog_choose_finish(GTK_ALERT_DIALOG(source), result, NULL)) {
        end_porta(self);
    } else if (porta_flow_is_closing(&self->flow)) {
        self->answer = g_timeout_add_seconds(SHUTDOWN_SECONDS, answer_timed_out, self);
    }
}

static gboolean answer_timed_out(gpointer data)
{
    PortaWindow *self = data;
    static const char *const buttons[] = {"Keep Waiting", "Force Quit", NULL};

    self->answer = 0;
    if (PORTA_STEP_FORCE_QUIT == porta_flow_timed_out(&self->flow)) {
        g_autoptr(GtkAlertDialog) dialog = gtk_alert_dialog_new("Porta is not responding");
        gtk_alert_dialog_set_detail(dialog, "Force quitting loses any unsaved changes in this window.");
        gtk_alert_dialog_set_buttons(dialog, buttons);
        gtk_alert_dialog_choose(dialog, GTK_WINDOW(self->window), NULL, force_quit_answered, self);
    }
    return G_SOURCE_REMOVE;
}

static gboolean on_close_request(GtkWindow *window, gpointer data)
{
    PortaWindow *self = data;

    (void) window;
    if (self->exited) {
        save_settings(self);
        return FALSE;
    }
    switch (porta_flow_close_requested(&self->flow)) {
    case PORTA_STEP_CLOSE_NOW:
        self->exited = TRUE;
        if (self->pid > 0) {
            kill(self->pid, SIGHUP);
        }
        save_settings(self);
        return FALSE;
    case PORTA_STEP_ASK: {
        g_autofree char *request = porta_close_request(self->token);
        write_to_porta(self, request, -1);
        self->answer = g_timeout_add_seconds(ANSWER_SECONDS, answer_timed_out, self);
        return TRUE;
    }
    default:
        return TRUE;
    }
}

static void on_destroy(GtkWidget *widget, gpointer data)
{
    PortaWindow *self = data;

    (void) widget;
    if (0 != self->answer) {
        g_source_remove(self->answer);
    }
    if (0 != self->reader) {
        g_source_remove(self->reader);
    }
    windows = g_list_remove(windows, self);
    if (menu_owner == self) {
        menu_owner = NULL;
    }
    self->closed = TRUE;
    release(self);
}

static void copy_to_clipboard(PortaWindow *self, const char *payload)
{
    const char *data = strchr(payload, ';');
    gsize length = 0;

    if (NULL == data) {
        return;
    }
    g_autofree guchar *text = g_base64_decode(data + 1, &length);
    g_autofree char *terminated = g_strndup((const char *) text, length);
    gdk_clipboard_set_text(gtk_widget_get_clipboard(GTK_WIDGET(self->window)), terminated);
}

/* The desktop's light or dark preference, from the settings portal: 1 is dark. */
static gboolean dark_preferred = FALSE;
static gboolean appearance_known = FALSE;

static void send_appearance(PortaWindow *self)
{
    /* The picker draws in it too: told while it waits, as the IDE is once ready. */
    if ((self->ready || self->picking) && appearance_known) {
        g_autofree char *message = porta_appearance(self->token, dark_preferred);
        write_to_porta(self, message, -1);
    }
}

static void appearance_from(GVariant *value)
{
    g_autoptr(GVariant) inner = g_variant_is_of_type(value, G_VARIANT_TYPE_VARIANT) ? g_variant_get_variant(value) : g_variant_ref(value);
    if (g_variant_is_of_type(inner, G_VARIANT_TYPE_UINT32)) {
        guint32 scheme = g_variant_get_uint32(inner);
        /* 1 dark, 2 light; 0 is no preference, which leaves Porta's own theme alone. */
        if (1 != scheme && 2 != scheme) {
            return;
        }
        dark_preferred = 1 == scheme;
        appearance_known = TRUE;
        for (GList *entry = windows; NULL != entry; entry = entry->next) {
            send_appearance(entry->data);
        }
    }
}

static void on_setting_changed(GDBusProxy *proxy, const char *sender, const char *signal, GVariant *parameters, gpointer data)
{
    const char *scope = NULL;
    const char *key = NULL;
    g_autoptr(GVariant) value = NULL;

    (void) proxy;
    (void) sender;
    (void) data;
    if (0 != g_strcmp0(signal, "SettingChanged")) {
        return;
    }
    g_variant_get(parameters, "(&s&sv)", &scope, &key, &value);
    if (0 == g_strcmp0(scope, "org.freedesktop.appearance") && 0 == g_strcmp0(key, "color-scheme")) {
        appearance_from(value);
    }
}

static void focus_window(GSimpleAction *action, GVariant *parameter, gpointer data)
{
    const char *token = g_variant_get_string(parameter, NULL);

    (void) action;
    (void) data;
    for (GList *entry = windows; NULL != entry; entry = entry->next) {
        PortaWindow *window = entry->data;
        if (0 == g_strcmp0(window->token, token)) {
            gtk_window_present(GTK_WINDOW(window->window));
        }
    }
}

static void follow_appearance(void)
{
    g_autoptr(GError) error = NULL;
    GDBusProxy *portal = g_dbus_proxy_new_for_bus_sync(G_BUS_TYPE_SESSION, G_DBUS_PROXY_FLAGS_NONE, NULL,
        "org.freedesktop.portal.Desktop", "/org/freedesktop/portal/desktop", "org.freedesktop.portal.Settings", NULL, &error);
    if (NULL == portal) {
        return; /* no portal: Porta keeps its own theme */
    }
    g_autoptr(GVariant) answer = g_dbus_proxy_call_sync(portal, "ReadOne", g_variant_new("(ss)", "org.freedesktop.appearance", "color-scheme"),
        G_DBUS_CALL_FLAGS_NONE, 2000, NULL, NULL);
    if (NULL != answer) {
        g_autoptr(GVariant) value = g_variant_get_child_value(answer, 0);
        appearance_from(value);
    }
    g_signal_connect(portal, "g-signal", G_CALLBACK(on_setting_changed), NULL);
}

static void on_startup(GApplication *application, gpointer data)
{
    /* What a notification runs when clicked: the window it came from comes forward. */
    static const GActionEntry actions[] = {{.name = "focus-window", .activate = focus_window, .parameter_type = "s"}};

    (void) data;
    g_action_map_add_action_entries(G_ACTION_MAP(application), actions, G_N_ELEMENTS(actions), NULL);
    follow_appearance();
}

/* Hands `path` to a window whose picker is up: it is that window's project now, so the next is not. */
static void give_to_picker(PortaWindow *self, const char *path)
{
    g_autofree char *request = porta_open_request(self->token, path);
    write_to_porta(self, request, -1);
    self->picking = FALSE;
    /* The same folder opened again while Porta boots comes here, not to a new window. */
    g_free(self->project_key);
    self->project_key = project_key(path);
}

static void on_open(GApplication *application, GFile **files, int count, const char *hint, gpointer data);
static PortaWindow *dropped_on;

static void on_folder_chosen(GObject *source, GAsyncResult *result, gpointer data)
{
    PortaWindow *self = data;
    GFile *folder = gtk_file_dialog_select_folder_finish(GTK_FILE_DIALOG(source), result, NULL);

    self->choosing = FALSE;
    /* Cancelled, or the window was given a project meanwhile: nothing to send. Otherwise it goes
     * where any open goes, this window first: a project open elsewhere is brought forward, not
     * opened twice. */
    if (NULL != folder && self->picking && !self->closed) {
        dropped_on = self;
        on_open(G_APPLICATION(gtk_window_get_application(GTK_WINDOW(self->window))), &folder, 1, "", NULL);
        dropped_on = NULL;
    }
    g_clear_object(&folder);
    release(self);
}

/* The picker's "Choose a folder…": the system's dialog, over this window. */
static void choose_folder(PortaWindow *self)
{
    g_autoptr(GtkFileDialog) dialog = gtk_file_dialog_new();

    /* Asked again while it is up, a second dialog would stack on the first. */
    if (self->choosing) {
        return;
    }
    self->choosing = TRUE;
    gtk_file_dialog_set_modal(dialog, TRUE);
    /* The window may close while the dialog is up: the answer still needs somewhere to land. */
    self->holds++;
    gtk_file_dialog_select_folder(dialog, GTK_WINDOW(self->window), NULL, on_folder_chosen, self);
}

static void received(PortaWindow *self, PortaOsc *osc)
{
    g_autofree char *value = NULL;
    PortaMessageKind kind;

    if (PORTA_OSC_CLIPBOARD == osc->code) {
        copy_to_clipboard(self, osc->payload);
        return;
    }
    kind = porta_message_parse(osc->payload, self->token, &value);
    if (PORTA_MESSAGE_READY == kind) {
        g_autofree char *name = g_path_get_basename(value);
        g_autofree char *title = g_strdup_printf("%s — Porta", name);
        g_autofree char *uri = g_filename_to_uri(value, NULL, NULL);
        g_free(self->project_key);
        self->project_key = project_key(value);
        g_free(self->title);
        self->title = g_steal_pointer(&title);
        gtk_window_set_title(GTK_WINDOW(self->window), self->title);
        /* The desktop's recent list: file choosers and launchers offer the project again. */
        if (NULL != uri) {
            gtk_recent_manager_add_item(gtk_recent_manager_get_default(), uri);
        }
        self->ready = TRUE;
        self->picking = FALSE;
        /* Reopen starts what is open now, which a switch may have changed. */
        g_free(self->project);
        self->project = g_strdup(value);
        send_appearance(self);
        for (guint i = 0; i < self->pending->len; i++) {
            g_autofree char *request = porta_open_request(self->token, g_ptr_array_index(self->pending, i));
            write_to_porta(self, request, -1);
        }
        g_ptr_array_set_size(self->pending, 0);
    }
    if (PORTA_MESSAGE_PICKING == kind && !self->ready) {
        /* Waiting again (a folder it was handed was not there): it owns no project yet. */
        self->picking = TRUE;
        g_clear_pointer(&self->project_key, g_free);
        send_appearance(self);
    }
    if (PORTA_MESSAGE_CHOOSE_FOLDER == kind && self->picking) {
        choose_folder(self);
    }
    if (PORTA_MESSAGE_PICKED == kind) {
        self->picking = FALSE;
    }
    if (PORTA_MESSAGE_NOTIFY == kind && !gtk_window_is_active(GTK_WINDOW(self->window))) {
        /* A run ended while the user was elsewhere: the desktop says so, and clicking brings this window. */
        g_autoptr(GNotification) notification = g_notification_new(NULL != self->title ? self->title : "Porta");
        g_notification_set_body(notification, value);
        g_notification_set_default_action_and_target(notification, "app.focus-window", "s", self->token);
        g_application_send_notification(G_APPLICATION(gtk_window_get_application(GTK_WINDOW(self->window))), NULL, notification);
    }
    if (PORTA_MESSAGE_STATE == kind && NULL != self->title) {
        /* A mark in the title: closing this window would lose work. */
        g_autofree char *marked = g_strdup_printf("• %s", self->title);
        gtk_window_set_title(GTK_WINDOW(self->window), 0 == g_strcmp0(value, "0") ? self->title : marked);
    }
    g_debug("message %d from Porta", kind);
    if (PORTA_MESSAGE_MENU == kind) {
        g_free(self->menu_json);
        self->menu_json = g_steal_pointer(&value);
        /* The active window's menus; until one is active, whichever window has some. */
        if (gtk_window_is_active(GTK_WINDOW(self->window)) || NULL == menu_owner || menu_owner == self) {
            show_menus(gtk_window_get_application(GTK_WINDOW(self->window)), self);
        }
    }
    PortaStep step = porta_flow_received(&self->flow, kind);
    if ((PORTA_STEP_STOP_WAITING == step || PORTA_STEP_WAIT_FOR_EXIT == step) && 0 != self->answer) {
        g_source_remove(self->answer);
        self->answer = 0;
    }
    if (PORTA_STEP_WAIT_FOR_EXIT == step) {
        self->answer = g_timeout_add_seconds(SHUTDOWN_SECONDS, answer_timed_out, self);
    }
}

static gboolean on_readable(int fd, GIOCondition condition, gpointer data)
{
    PortaWindow *self = data;
    guint8 buffer[READ_SIZE];
    gssize count = read(fd, buffer, sizeof buffer);

    (void) condition;
    if (count > 0) {
        g_autoptr(GByteArray) output = g_byte_array_sized_new((guint) count);
        g_autoptr(GPtrArray) found = g_ptr_array_new_with_free_func(porta_osc_free);
        porta_filter_consume(self->filter, buffer, (gsize) count, output, found);
        vte_terminal_feed(self->terminal, (const char *) output->data, output->len);
        for (guint i = 0; i < found->len; i++) {
            received(self, found->pdata[i]);
        }
        return G_SOURCE_CONTINUE;
    }
    if (count < 0 && (EAGAIN == errno || EINTR == errno)) {
        return G_SOURCE_CONTINUE;
    }
    self->reader = 0;
    return G_SOURCE_REMOVE;
}

static void on_commit(VteTerminal *terminal, char *text, guint size, gpointer data)
{
    (void) terminal;
    write_to_porta(data, text, size);
}

static gboolean sync_size(GtkWidget *widget, GdkFrameClock *clock, gpointer data)
{
    PortaWindow *self = data;
    glong columns = vte_terminal_get_column_count(self->terminal);
    glong rows = vte_terminal_get_row_count(self->terminal);

    (void) widget;
    (void) clock;
    if ((columns != self->columns || rows != self->rows) && NULL != self->pty) {
        self->columns = columns;
        self->rows = rows;
        vte_pty_set_size(self->pty, (int) rows, (int) columns, NULL);
    }
    return G_SOURCE_CONTINUE;
}

static void start_porta(PortaWindow *self);
static void offer_recovery(PortaWindow *self);

static void recovery_answered(GObject *source, GAsyncResult *result, gpointer data)
{
    PortaWindow *self = data;
    int answer = gtk_alert_dialog_choose_finish(GTK_ALERT_DIALOG(source), result, NULL);

    /* The dialog's own reference, taken when it was shown: the window may have closed since. */
    if (self->closed) {
        release(self);
        return;
    }
    if (0 == answer) {
        /* The same project, started again in this window: RIS clears what the stopped one left. */
        vte_terminal_feed(self->terminal, "\x1b" "c", -1);
        porta_filter_free(self->filter);
        self->filter = porta_filter_new();
        memset(&self->flow, 0, sizeof self->flow);
        self->exited = FALSE;
        self->ready = FALSE;
        self->holds++;
        gtk_window_set_title(GTK_WINDOW(self->window), "Porta");
        start_porta(self);
    } else if (1 == answer) {
        g_autofree char *log = g_build_filename(NULL != self->project_key ? self->project_key : g_get_home_dir(), ".porta", "error.log", NULL);
        g_autoptr(GFile) file = g_file_new_for_path(log);
        g_autoptr(GtkFileLauncher) launcher = gtk_file_launcher_new(file);
        /* Shown in its folder, never launched: the project decides what that path is. */
        gtk_file_launcher_open_containing_folder(launcher, GTK_WINDOW(self->window), NULL, NULL, NULL);
        /* Still stopped: the choice stays on offer. */
        offer_recovery(self);
    } else {
        gtk_window_destroy(GTK_WINDOW(self->window));
    }
    release(self);
}

/* A window whose Porta stopped says what can be done, rather than just staying. */
static void offer_recovery(PortaWindow *self)
{
    static const char *const buttons[] = {"Reopen", "Show Log", "Close", NULL};
    g_autoptr(GtkAlertDialog) dialog = gtk_alert_dialog_new("Porta stopped");

    gtk_alert_dialog_set_detail(dialog, "What it printed is in the window. The project's .porta/error.log may say more.");
    gtk_alert_dialog_set_buttons(dialog, buttons);
    self->holds++;
    gtk_alert_dialog_choose(dialog, GTK_WINDOW(self->window), NULL, recovery_answered, self);
}

static void on_child_exit(GPid pid, int status, gpointer data)
{
    PortaWindow *self = data;
    gboolean exited = WIFEXITED(status);

    g_spawn_close_pid(pid);
    self->pid = 0;
    self->exited = TRUE;
    self->picking = FALSE;
    if (!self->closed) {
        if (porta_flow_closes_on_exit(&self->flow, exited, exited ? WEXITSTATUS(status) : 0)) {
            destroy_window(self);
        } else {
            gtk_window_set_title(GTK_WINDOW(self->window), "Porta — stopped");
            offer_recovery(self);
        }
    }
    release(self);
}

static void on_spawned(VtePty *pty, GAsyncResult *result, gpointer data)
{
    PortaWindow *self = data;
    g_autoptr(GError) error = NULL;

    if (!vte_pty_spawn_finish(pty, result, &self->pid, &error)) {
        if (!self->closed) {
            g_autofree char *message = g_strdup_printf("Porta could not start: %s\r\n", error->message);
            vte_terminal_feed(self->terminal, message, -1);
            gtk_window_set_title(GTK_WINDOW(self->window), "Porta — stopped");
            self->exited = TRUE;
        }
        release(self);
        return;
    }
    if (self->closed) {
        /* The window was closed while Porta was still starting. */
        kill(self->pid, SIGHUP);
    }
    g_child_watch_add(self->pid, on_child_exit, self);
}

/* Ctrl+Shift+letter and modified Enter, which a plain terminal cannot tell apart from their neighbours. */
static gboolean on_key(GtkEventControllerKey *controller, guint keyval, guint keycode, GdkModifierType state, gpointer data)
{
    PortaWindow *self = data;
    gboolean ctrl = 0 != (state & GDK_CONTROL_MASK);
    gboolean shift = 0 != (state & GDK_SHIFT_MASK);
    gboolean alt = 0 != (state & GDK_ALT_MASK);
    guint lower = gdk_keyval_to_lower(keyval);

    (void) controller;
    (void) keycode;
    if (ctrl && shift && (GDK_KEY_plus == keyval || GDK_KEY_equal == lower)) {
        vte_terminal_set_font_scale(self->terminal, vte_terminal_get_font_scale(self->terminal) * 1.1);
        return TRUE;
    }
    if (ctrl && shift && (GDK_KEY_underscore == keyval || GDK_KEY_minus == lower)) {
        vte_terminal_set_font_scale(self->terminal, vte_terminal_get_font_scale(self->terminal) / 1.1);
        return TRUE;
    }
    if (shift && GDK_KEY_Insert == keyval) {
        vte_terminal_paste_clipboard(self->terminal);
        return TRUE;
    }
    if ((ctrl && shift && lower >= GDK_KEY_a && lower <= GDK_KEY_z) || ((GDK_KEY_Return == keyval) && (ctrl || shift || alt))) {
        g_autofree char *sequence = porta_key_sequence(GDK_KEY_Return == keyval ? 13 : lower, shift, alt, ctrl);
        write_to_porta(self, sequence, -1);
        return TRUE;
    }
    return FALSE;
}

/* Whether this desktop shows application menus itself; most Linux desktops draw none. */
static gboolean desktop_shows_menus(void)
{
    gboolean shown = FALSE;
    g_object_get(gtk_settings_get_default(), "gtk-shell-shows-menubar", &shown, NULL);
    return shown;
}

static void on_active(GObject *window, GParamSpec *spec, gpointer data)
{
    (void) spec;
    if (gtk_window_is_active(GTK_WINDOW(window))) {
        show_menus(gtk_window_get_application(GTK_WINDOW(window)), data);
    }
}

/* Porta, on a new PTY, in this window. Holds one reference for the child, released when it ends. */
static void start_porta(PortaWindow *self)
{
    g_autoptr(GError) error = NULL;
    g_autofree char *runtime = runtime_dir();

    if (0 != self->reader) {
        g_source_remove(self->reader);
        self->reader = 0;
    }
    g_clear_object(&self->pty);
    self->pty = vte_pty_new_sync(VTE_PTY_DEFAULT, NULL, &error);
    if (NULL == self->pty) {
        vte_terminal_feed(self->terminal, error->message, -1);
        self->exited = TRUE;
        release(self);
        return;
    }
    self->master = vte_pty_get_fd(self->pty);
    /* VTE turns packet mode on, which puts a status byte before every read.
     * This window reads the PTY itself, so it wants the bytes alone. */
    int packets = 0;
    ioctl(self->master, TIOCPKT, &packets);
    g_unix_set_fd_nonblocking(self->master, TRUE, NULL);
    self->reader = g_unix_fd_add(self->master, G_IO_IN | G_IO_HUP | G_IO_ERR, on_readable, self);

    g_auto(GStrv) argv = porta_launch_argv(runtime, self->project);
    g_auto(GStrv) inherited = g_get_environ();
    g_auto(GStrv) env = porta_launch_env(inherited, self->token, desktop_shows_menus(), appearance_known ? (dark_preferred ? "dark" : "light") : NULL);
    vte_pty_spawn_async(self->pty, NULL != self->project ? self->project : g_get_home_dir(), argv, env, G_SPAWN_DEFAULT, NULL, NULL, NULL, -1, NULL, (GAsyncReadyCallback) on_spawned, self);
}

static void on_open(GApplication *application, GFile **files, int count, const char *hint, gpointer data);

/* The window a drop is being handled for: what lands in it is already where the user is looking, so it
 * is not presented. GNOME on Wayland refuses a present without an activation token, which a drop does
 * not carry, and says "Porta is ready" instead. Another project's window is still brought forward. */
static PortaWindow *dropped_on = NULL;

/* Files and folders dropped on the window go where opening them with Porta would. */
static gboolean on_drop(GtkDropTarget *target, const GValue *value, double x, double y, gpointer data)
{
    PortaWindow *self = data;
    GSList *list = gdk_file_list_get_files(g_value_get_boxed(value));
    guint count = g_slist_length(list);
    g_autofree GFile **files = g_new0(GFile *, count + 1);
    guint i = 0;

    (void) target;
    (void) x;
    (void) y;
    for (GSList *entry = list; NULL != entry; entry = entry->next) {
        files[i++] = entry->data;
    }
    g_slist_free(list);
    if (0 == count) {
        return FALSE;
    }
    dropped_on = self;
    on_open(G_APPLICATION(gtk_window_get_application(GTK_WINDOW(self->window))), files, (int) count, "", NULL);
    dropped_on = NULL;
    return TRUE;
}

static void open_window(GtkApplication *application, const char *project)
{
    PortaWindow *self = g_new0(PortaWindow, 1);
    g_autoptr(GKeyFile) settings = load_settings();
    int width = g_key_file_get_integer(settings, "window", "width", NULL);
    int height = g_key_file_get_integer(settings, "window", "height", NULL);
    double scale = g_key_file_get_double(settings, "window", "scale", NULL);
    GtkEventController *keys = gtk_event_controller_key_new();

    self->holds = 2;
    self->project = g_strdup(project);
    self->pending = g_ptr_array_new_with_free_func(g_free);
    self->filter = porta_filter_new();
    self->token = porta_new_token();
    self->project_key = NULL != project ? project_key(project) : NULL;
    self->master = -1;
    self->window = GTK_APPLICATION_WINDOW(gtk_application_window_new(application));
    self->terminal = VTE_TERMINAL(vte_terminal_new());
#if VTE_CHECK_VERSION(0, 78, 0)
    /* Orca reads the terminal from VTE 0.78, but only from 0.82 without being asked. */
    vte_terminal_set_enable_a11y(self->terminal, TRUE);
#endif
    windows = g_list_append(windows, self);

    gtk_window_set_title(GTK_WINDOW(self->window), "Porta");
    gtk_window_set_icon_name(GTK_WINDOW(self->window), APP_ID);
    gtk_window_set_default_size(GTK_WINDOW(self->window), width > 0 ? width : 1100, height > 0 ? height : 720);
    if (g_key_file_get_boolean(settings, "window", "maximized", NULL)) {
        gtk_window_maximize(GTK_WINDOW(self->window));
    }
    if (scale > 0) {
        vte_terminal_set_font_scale(self->terminal, scale);
    }
    apply_font(self);
    gtk_window_set_child(GTK_WINDOW(self->window), GTK_WIDGET(self->terminal));

    /* The window's own menu, in its title bar: Porta's menus are Porta's, the font is the window's. */
    const GActionEntry window_actions[] = {{.name = "font", .activate = choose_font}};
    g_action_map_add_action_entries(G_ACTION_MAP(self->window), window_actions, G_N_ELEMENTS(window_actions), self);
    g_autoptr(GMenu) window_menu = g_menu_new();
    g_menu_append(window_menu, "Font…", "win.font");
    GtkWidget *menu_button = gtk_menu_button_new();
    gtk_menu_button_set_icon_name(GTK_MENU_BUTTON(menu_button), "open-menu-symbolic");
    gtk_menu_button_set_menu_model(GTK_MENU_BUTTON(menu_button), G_MENU_MODEL(window_menu));
    gtk_widget_set_tooltip_text(menu_button, "Window");
    g_signal_connect_object(gtk_menu_button_get_popover(GTK_MENU_BUTTON(menu_button)), "closed", G_CALLBACK(menu_closed), self->terminal, 0);
    GtkWidget *header = gtk_header_bar_new();
    gtk_header_bar_pack_end(GTK_HEADER_BAR(header), menu_button);
    gtk_window_set_titlebar(GTK_WINDOW(self->window), header);
    GtkDropTarget *drop = gtk_drop_target_new(GDK_TYPE_FILE_LIST, GDK_ACTION_COPY);
    g_signal_connect(drop, "drop", G_CALLBACK(on_drop), self);
    gtk_widget_add_controller(GTK_WIDGET(self->terminal), GTK_EVENT_CONTROLLER(drop));
    gtk_event_controller_set_propagation_phase(keys, GTK_PHASE_CAPTURE);
    g_signal_connect(keys, "key-pressed", G_CALLBACK(on_key), self);
    gtk_widget_add_controller(GTK_WIDGET(self->terminal), keys);
    g_signal_connect(self->terminal, "commit", G_CALLBACK(on_commit), self);
    g_signal_connect(self->window, "close-request", G_CALLBACK(on_close_request), self);
    g_signal_connect(self->window, "destroy", G_CALLBACK(on_destroy), self);
    g_signal_connect(self->window, "notify::is-active", G_CALLBACK(on_active), self);
    gtk_widget_add_tick_callback(GTK_WIDGET(self->terminal), sync_size, self, NULL);
    gtk_window_present(GTK_WINDOW(self->window));

    start_porta(self);
}

/* The system menu: one action per item, since each is enabled and ticked on its own. */
static void on_menu_item(GSimpleAction *action, GVariant *parameter, gpointer data)
{
    int menu = GPOINTER_TO_INT(g_object_get_data(G_OBJECT(action), "menu"));
    int item = GPOINTER_TO_INT(g_object_get_data(G_OBJECT(action), "item"));

    (void) parameter;
    (void) data;
    if (NULL != menu_owner && NULL != menu_generation) {
        g_autofree char *choice = porta_menu_choice(menu_owner->token, menu_generation, menu, item);
        write_to_porta(menu_owner, choice, -1);
    }
}

static void show_menus(GtkApplication *application, PortaWindow *self)
{
    g_autoptr(JsonParser) parser = json_parser_new();
    g_autoptr(GMenu) bar = g_menu_new();
    JsonObject *root;
    JsonArray *menus;

    g_autoptr(GError) error = NULL;

    menu_owner = self;
    if (NULL == self->menu_json || !json_parser_load_from_data(parser, self->menu_json, -1, &error)) {
        g_debug("no menus to show: %s", NULL != error ? error->message : "none received");
        return;
    }
    root = json_node_get_object(json_parser_get_root(parser));
    menus = json_object_get_array_member(root, "menus");
    g_free(menu_generation);
    menu_generation = g_strdup(json_object_get_string_member(root, "generation"));

    for (guint m = 0; m < json_array_get_length(menus); m++) {
        JsonObject *menu = json_array_get_object_element(menus, m);
        JsonArray *items = json_object_get_array_member(menu, "items");
        g_autoptr(GMenu) submenu = g_menu_new();
        g_autoptr(GMenu) section = g_menu_new();

        for (guint i = 0; i < json_array_get_length(items); i++) {
            JsonObject *item = json_array_get_object_element(items, i);
            g_autofree char *name = g_strdup_printf("porta-%u-%u", m, i);
            g_autofree char *detailed = g_strdup_printf("app.%s", name);
            JsonNode *checked = json_object_get_member(item, "checked");
            g_autoptr(GSimpleAction) action = NULL;

            if (json_object_get_boolean_member(item, "separator")) {
                g_menu_append_section(submenu, NULL, G_MENU_MODEL(section));
                g_clear_object(&section);
                section = g_menu_new();
                continue;
            }
            action = JSON_NODE_HOLDS_NULL(checked)
                ? g_simple_action_new(name, NULL)
                : g_simple_action_new_stateful(name, NULL, g_variant_new_boolean(json_node_get_boolean(checked)));
            g_object_set_data(G_OBJECT(action), "menu", GINT_TO_POINTER(m));
            g_object_set_data(G_OBJECT(action), "item", GINT_TO_POINTER(i));
            g_simple_action_set_enabled(action, json_object_get_boolean_member(item, "enabled"));
            g_signal_connect(action, "activate", G_CALLBACK(on_menu_item), NULL);
            g_action_map_add_action(G_ACTION_MAP(application), G_ACTION(action));
            g_menu_append(section, json_object_get_string_member(item, "label"), detailed);
        }
        g_menu_append_section(submenu, NULL, G_MENU_MODEL(section));
        g_menu_append_submenu(bar, json_object_get_string_member(menu, "title"), G_MENU_MODEL(submenu));
    }
    gtk_application_set_menubar(application, G_MENU_MODEL(bar));
    g_debug("%u menus shown", json_array_get_length(menus));
}

static void on_activate(GApplication *application, gpointer data)
{
    GList *last = g_list_last(windows);

    (void) data;
    if (NULL != last) {
        gtk_window_present(GTK_WINDOW(((PortaWindow *) last->data)->window));
        return;
    }
    open_window(GTK_APPLICATION(application), NULL);
}

static void on_open(GApplication *application, GFile **files, int count, const char *hint, gpointer data)
{
    (void) hint;
    (void) data;
    for (int i = 0; i < count; i++) {
        g_autofree char *path = g_file_get_path(files[i]);
        g_autofree char *key = NULL != path ? project_key(path) : NULL;
        gboolean found = FALSE;

        if (NULL == path) {
            continue;
        }
        for (GList *entry = windows; NULL != entry && !found; entry = entry->next) {
            PortaWindow *window = entry->data;
            if (0 == g_strcmp0(window->project_key, key)) {
                if (window != dropped_on) {
                    gtk_window_present(GTK_WINDOW(window->window));
                }
                found = TRUE;
            } else if (NULL != window->project_key && g_str_has_prefix(key, window->project_key) && '/' == key[strlen(window->project_key)]) {
                /* A file, or a folder inside a project already open, goes to that window,
                 * once it is ready if it is still starting. */
                if (window->ready) {
                    g_autofree char *request = porta_open_request(window->token, key);
                    write_to_porta(window, request, -1);
                } else {
                    g_ptr_array_add(window->pending, g_strdup(key));
                }
                if (window != dropped_on) {
                    gtk_window_present(GTK_WINDOW(window->window));
                }
                found = TRUE;
            }
        }
        if (!found) {
            /* A window still waiting for a project takes it, the one dropped on or in front first:
             * no second window beside it. */
            PortaWindow *picker = NULL != dropped_on && dropped_on->picking ? dropped_on : NULL;
            for (GList *entry = windows; NULL == picker && NULL != entry; entry = entry->next) {
                PortaWindow *window = entry->data;
                if (window->picking && gtk_window_is_active(GTK_WINDOW(window->window))) {
                    picker = window;
                }
            }
            for (GList *entry = windows; NULL == picker && NULL != entry; entry = entry->next) {
                if (((PortaWindow *) entry->data)->picking) {
                    picker = entry->data;
                }
            }
            if (NULL == picker) {
                open_window(GTK_APPLICATION(application), path);
                continue;
            }
            give_to_picker(picker, path);
            if (picker != dropped_on) {
                gtk_window_present(GTK_WINDOW(picker->window));
            }
        }
    }
}

int main(int argc, char **argv)
{
    g_autoptr(GtkApplication) application = gtk_application_new(APP_ID, G_APPLICATION_HANDLES_OPEN);

    g_set_application_name("Porta");
    g_signal_connect(application, "activate", G_CALLBACK(on_activate), NULL);
    g_signal_connect(application, "open", G_CALLBACK(on_open), NULL);
    g_signal_connect(application, "startup", G_CALLBACK(on_startup), NULL);
    return g_application_run(G_APPLICATION(application), argc, argv);
}
