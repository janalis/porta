/* Porta's Linux window: GTK 4 and VTE around one Porta per project. */
#include "core.h"

#include <errno.h>
#include <gio/gio.h>
#include <glib-unix.h>
#include <gtk/gtk.h>
#include <json-glib/json-glib.h>
#include <signal.h>
#include <stdlib.h>
#include <string.h>
#include <sys/wait.h>
#include <unistd.h>
#include <vte/vte.h>

#define APP_ID "io.github.janalis.porta"
#define ANSWER_SECONDS 4
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
    GPid pid;
    int master;
    guint reader;
    guint answer;
    glong columns;
    glong rows;
    gboolean exited;
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
    gtk_window_destroy(GTK_WINDOW(self->window));
}

static void force_quit_answered(GObject *source, GAsyncResult *result, gpointer data)
{
    /* Button 1 is "Force Quit"; anything else keeps waiting. */
    if (1 == gtk_alert_dialog_choose_finish(GTK_ALERT_DIALOG(source), result, NULL)) {
        end_porta(data);
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
        return FALSE;
    }
    switch (porta_flow_close_requested(&self->flow)) {
    case PORTA_STEP_CLOSE_NOW:
        self->exited = TRUE;
        if (self->pid > 0) {
            kill(self->pid, SIGHUP);
        }
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
    save_settings(self);
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
    porta_filter_free(self->filter);
    g_clear_object(&self->pty);
    g_free(self->token);
    g_free(self->project_key);
    g_free(self->menu_json);
    g_free(self);
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
        g_free(self->project_key);
        self->project_key = project_key(value);
        gtk_window_set_title(GTK_WINDOW(self->window), title);
    }
    if (PORTA_MESSAGE_MENU == kind) {
        g_free(self->menu_json);
        self->menu_json = g_steal_pointer(&value);
        if (gtk_window_is_active(GTK_WINDOW(self->window))) {
            show_menus(gtk_window_get_application(GTK_WINDOW(self->window)), self);
        }
    }
    if (PORTA_STEP_STOP_WAITING == porta_flow_received(&self->flow, kind) && 0 != self->answer) {
        g_source_remove(self->answer);
        self->answer = 0;
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

static void on_child_exit(GPid pid, int status, gpointer data)
{
    PortaWindow *self = data;
    gboolean exited = WIFEXITED(status);

    g_spawn_close_pid(pid);
    self->pid = 0;
    self->exited = TRUE;
    if (porta_flow_closes_on_exit(&self->flow, exited, exited ? WEXITSTATUS(status) : 0)) {
        gtk_window_destroy(GTK_WINDOW(self->window));
        return;
    }
    gtk_window_set_title(GTK_WINDOW(self->window), "Porta — stopped");
}

static void on_spawned(VtePty *pty, GAsyncResult *result, gpointer data)
{
    PortaWindow *self = data;
    g_autoptr(GError) error = NULL;

    if (!vte_pty_spawn_finish(pty, result, &self->pid, &error)) {
        g_autofree char *message = g_strdup_printf("Porta could not start: %s\r\n", error->message);
        vte_terminal_feed(self->terminal, message, -1);
        gtk_window_set_title(GTK_WINDOW(self->window), "Porta — stopped");
        self->exited = TRUE;
        return;
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

static void open_window(GtkApplication *application, const char *project)
{
    PortaWindow *self = g_new0(PortaWindow, 1);
    g_autoptr(GKeyFile) settings = load_settings();
    g_autoptr(GError) error = NULL;
    g_autofree char *runtime = runtime_dir();
    int width = g_key_file_get_integer(settings, "window", "width", NULL);
    int height = g_key_file_get_integer(settings, "window", "height", NULL);
    double scale = g_key_file_get_double(settings, "window", "scale", NULL);
    GtkEventController *keys = gtk_event_controller_key_new();

    self->filter = porta_filter_new();
    self->token = porta_new_token();
    self->project_key = NULL != project ? project_key(project) : NULL;
    self->master = -1;
    self->window = GTK_APPLICATION_WINDOW(gtk_application_window_new(application));
    self->terminal = VTE_TERMINAL(vte_terminal_new());
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
    gtk_window_set_child(GTK_WINDOW(self->window), GTK_WIDGET(self->terminal));
    gtk_event_controller_set_propagation_phase(keys, GTK_PHASE_CAPTURE);
    g_signal_connect(keys, "key-pressed", G_CALLBACK(on_key), self);
    gtk_widget_add_controller(GTK_WIDGET(self->terminal), keys);
    g_signal_connect(self->terminal, "commit", G_CALLBACK(on_commit), self);
    g_signal_connect(self->window, "close-request", G_CALLBACK(on_close_request), self);
    g_signal_connect(self->window, "destroy", G_CALLBACK(on_destroy), self);
    g_signal_connect(self->window, "notify::is-active", G_CALLBACK(on_active), self);
    gtk_widget_add_tick_callback(GTK_WIDGET(self->terminal), sync_size, self, NULL);
    gtk_window_present(GTK_WINDOW(self->window));

    self->pty = vte_pty_new_sync(VTE_PTY_DEFAULT, NULL, &error);
    if (NULL == self->pty) {
        vte_terminal_feed(self->terminal, error->message, -1);
        self->exited = TRUE;
        return;
    }
    self->master = vte_pty_get_fd(self->pty);
    g_unix_set_fd_nonblocking(self->master, TRUE, NULL);
    self->reader = g_unix_fd_add(self->master, G_IO_IN | G_IO_HUP | G_IO_ERR, on_readable, self);

    g_auto(GStrv) argv = porta_launch_argv(runtime, project);
    g_auto(GStrv) inherited = g_get_environ();
    g_auto(GStrv) env = porta_launch_env(inherited, self->token, desktop_shows_menus());
    vte_pty_spawn_async(self->pty, NULL != project ? project : g_get_home_dir(), argv, env, G_SPAWN_DEFAULT, NULL, NULL, NULL, -1, NULL, (GAsyncReadyCallback) on_spawned, self);
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

    menu_owner = self;
    if (NULL == self->menu_json || !json_parser_load_from_data(parser, self->menu_json, -1, NULL)) {
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
                gtk_window_present(GTK_WINDOW(window->window));
                found = TRUE;
            }
        }
        if (!found) {
            open_window(GTK_APPLICATION(application), path);
        }
    }
}

int main(int argc, char **argv)
{
    g_autoptr(GtkApplication) application = gtk_application_new(APP_ID, G_APPLICATION_HANDLES_OPEN);

    g_set_application_name("Porta");
    g_signal_connect(application, "activate", G_CALLBACK(on_activate), NULL);
    g_signal_connect(application, "open", G_CALLBACK(on_open), NULL);
    return g_application_run(G_APPLICATION(application), argc, argv);
}
