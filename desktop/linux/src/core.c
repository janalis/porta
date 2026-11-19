#include "core.h"

#include <string.h>
#include <sys/random.h>
#include <unistd.h>

#define LONGEST_PAYLOAD (4 * 1024 * 1024)
#define ESC 0x1b
#define BEL 0x07
#define NAMESPACE "porta-desktop"

enum { FLOW_STARTING, FLOW_READY, FLOW_ASKING, FLOW_CLOSING };

static const char *const PREFIXES[] = {"\x1b]7777;", "\x1b]52;"};
static const int CODES[] = {PORTA_OSC_PRIVATE, PORTA_OSC_CLIPBOARD};
#define PREFIX_COUNT G_N_ELEMENTS(PREFIXES)

struct PortaFilter {
    GByteArray *held; /* a prefix matched so far */
    GByteArray *payload;
    int code; /* 0 outside a private OSC */
    gboolean saw_escape;
};

PortaFilter *porta_filter_new(void)
{
    PortaFilter *filter = g_new0(PortaFilter, 1);
    filter->held = g_byte_array_new();
    filter->payload = g_byte_array_new();
    return filter;
}

void porta_filter_free(PortaFilter *filter)
{
    g_byte_array_unref(filter->held);
    g_byte_array_unref(filter->payload);
    g_free(filter);
}

void porta_osc_free(gpointer osc)
{
    g_free(((PortaOsc *) osc)->payload);
    g_free(osc);
}

static void finish_payload(PortaFilter *filter, GPtrArray *found)
{
    PortaOsc *osc = g_new0(PortaOsc, 1);
    osc->code = filter->code;
    osc->payload = g_strndup((const char *) filter->payload->data, filter->payload->len);
    g_ptr_array_add(found, osc);
    g_byte_array_set_size(filter->payload, 0);
    filter->code = 0;
    filter->saw_escape = FALSE;
}

static void read_payload(PortaFilter *filter, guint8 byte, GByteArray *output, GPtrArray *found)
{
    static const guint8 escape = ESC;

    if (byte == BEL || (filter->saw_escape && byte == '\\')) {
        finish_payload(filter, found);
        return;
    }
    if (filter->saw_escape) {
        g_byte_array_append(filter->payload, &escape, 1);
        filter->saw_escape = FALSE;
    }
    if (byte == ESC) {
        filter->saw_escape = TRUE;
        return;
    }
    g_byte_array_append(filter->payload, &byte, 1);
    if (filter->payload->len > LONGEST_PAYLOAD) {
        /* Not ours after all: nothing Porta sends is this long. */
        for (gsize i = 0; i < PREFIX_COUNT; i++) {
            if (CODES[i] == filter->code) {
                g_byte_array_append(output, (const guint8 *) PREFIXES[i], strlen(PREFIXES[i]));
            }
        }
        g_byte_array_append(output, filter->payload->data, filter->payload->len);
        g_byte_array_set_size(filter->payload, 0);
        filter->code = 0;
    }
}

/* Whether the held bytes plus nothing else still begin some prefix; sets the code on a full match. */
static gboolean held_matches(PortaFilter *filter)
{
    for (gsize i = 0; i < PREFIX_COUNT; i++) {
        gsize length = strlen(PREFIXES[i]);
        if (filter->held->len <= length && 0 == memcmp(filter->held->data, PREFIXES[i], filter->held->len)) {
            if (filter->held->len == length) {
                filter->code = CODES[i];
                g_byte_array_set_size(filter->held, 0);
            }
            return TRUE;
        }
    }
    return FALSE;
}

static void match_prefix(PortaFilter *filter, guint8 byte, GByteArray *output)
{
    g_byte_array_append(filter->held, &byte, 1);
    if (held_matches(filter)) {
        return;
    }
    /* The last byte may itself start a sequence. */
    g_byte_array_append(output, filter->held->data, filter->held->len - 1);
    g_byte_array_set_size(filter->held, 0);
    if (byte == ESC) {
        g_byte_array_append(filter->held, &byte, 1);
    } else {
        g_byte_array_append(output, &byte, 1);
    }
}

void porta_filter_consume(PortaFilter *filter, const guint8 *bytes, gsize length, GByteArray *output, GPtrArray *found)
{
    for (gsize i = 0; i < length; i++) {
        if (0 != filter->code) {
            read_payload(filter, bytes[i], output, found);
        } else {
            match_prefix(filter, bytes[i], output);
        }
    }
}

static char *decoded(const char *base64)
{
    gsize length = 0;
    guchar *data = g_base64_decode(base64, &length);
    char *text = g_strndup((const char *) data, length);
    g_free(data);
    return text;
}

PortaMessageKind porta_message_parse(const char *payload, const char *token, char **value)
{
    g_auto(GStrv) fields = g_strsplit(payload, ";", 5);
    guint count = g_strv_length(fields);
    PortaMessageKind kind = PORTA_MESSAGE_NONE;

    *value = NULL;
    if (count < 3 || 0 != g_strcmp0(fields[0], NAMESPACE) || 0 != g_strcmp0(fields[1], token)) {
        return kind;
    }
    if (3 == count && 0 == g_strcmp0(fields[2], "accepted")) {
        kind = PORTA_MESSAGE_ACCEPTED;
    } else if (3 == count && 0 == g_strcmp0(fields[2], "closing")) {
        kind = PORTA_MESSAGE_CLOSING;
    } else if (4 == count && 0 == g_strcmp0(fields[2], "ready")) {
        kind = PORTA_MESSAGE_READY;
        *value = decoded(fields[3]);
    } else if (4 == count && 0 == g_strcmp0(fields[2], "menu")) {
        kind = PORTA_MESSAGE_MENU;
        *value = decoded(fields[3]);
    }
    return kind;
}

char *porta_close_request(const char *token)
{
    return g_strdup_printf("\x1b]7777;" NAMESPACE ";%s;close\x1b\\", token);
}

char *porta_menu_choice(const char *token, const char *generation, int menu, int item)
{
    return g_strdup_printf("\x1b]7777;" NAMESPACE ";%s;menu;%s;%d;%d\x1b\\", token, generation, menu, item);
}

PortaStep porta_flow_close_requested(PortaCloseFlow *flow)
{
    switch (flow->state) {
    case FLOW_STARTING:
        return PORTA_STEP_CLOSE_NOW;
    case FLOW_READY:
        flow->state = FLOW_ASKING;
        return PORTA_STEP_ASK;
    default:
        return PORTA_STEP_WAIT;
    }
}

PortaStep porta_flow_received(PortaCloseFlow *flow, PortaMessageKind message)
{
    switch (message) {
    case PORTA_MESSAGE_READY:
        flow->state = FLOW_READY;
        flow->was_ready = TRUE;
        return PORTA_STEP_WAIT;
    case PORTA_MESSAGE_ACCEPTED:
        /* Unsaved work: Porta has said why, and the window stays. */
        flow->state = FLOW_READY;
        return PORTA_STEP_STOP_WAITING;
    case PORTA_MESSAGE_CLOSING:
        flow->state = FLOW_CLOSING;
        return PORTA_STEP_WAIT_FOR_EXIT;
    default:
        return PORTA_STEP_WAIT;
    }
}

PortaStep porta_flow_timed_out(PortaCloseFlow *flow)
{
    if (FLOW_CLOSING == flow->state) {
        return PORTA_STEP_FORCE_QUIT;
    }
    if (FLOW_ASKING != flow->state) {
        return PORTA_STEP_WAIT;
    }
    flow->state = FLOW_READY;
    return PORTA_STEP_FORCE_QUIT;
}

gboolean porta_flow_is_closing(const PortaCloseFlow *flow)
{
    return FLOW_CLOSING == flow->state;
}

gboolean porta_flow_closes_on_exit(const PortaCloseFlow *flow, gboolean exited, int status)
{
    /* A failure to start stays on screen: what it printed is the only explanation. */
    return exited ? 0 == status : flow->was_ready;
}

char *porta_new_token(void)
{
    guint8 bytes[16];
    GString *token = g_string_sized_new(32);

    /* The secret that tells the window's requests from pasted bytes: never a plain PRNG. */
    if (0 != getentropy(bytes, sizeof bytes)) {
        g_error("porta-desktop: the system has no source of randomness");
    }
    for (gsize i = 0; i < sizeof bytes; i++) {
        g_string_append_printf(token, "%02x", bytes[i]);
    }
    return g_string_free(token, FALSE);
}

char **porta_launch_argv(const char *runtime, const char *project)
{
    GStrvBuilder *builder = g_strv_builder_new();
    g_strv_builder_take(builder, g_build_filename(runtime, "php", NULL));
    g_strv_builder_add_many(builder, "-n", "-d", "memory_limit=-1", "-d", "display_errors=0", NULL);
    g_strv_builder_take(builder, g_build_filename(runtime, "porta.phar", NULL));
    if (NULL != project) {
        g_strv_builder_add(builder, project);
    }
    char **argv = g_strv_builder_end(builder);
    g_strv_builder_unref(builder);
    return argv;
}

char **porta_launch_env(char **inherited, const char *token, gboolean system_menu)
{
    static const char *const DROPPED[] = {"PHPRC", "PHP_INI_SCAN_DIR", "WT_SESSION", "TERM_PROGRAM", "TERM_PROGRAM_VERSION", "TMUX", "TMUX_PANE", "PORTA_DESKTOP_MENU"};
    char **env = g_strdupv(inherited);

    for (gsize i = 0; i < G_N_ELEMENTS(DROPPED); i++) {
        env = g_environ_unsetenv(env, DROPPED[i]);
    }
    g_auto(GStrv) names = g_strdupv(env);
    for (guint i = 0; NULL != names[i]; i++) {
        if (g_str_has_prefix(names[i], "LD_")) {
            g_autofree char *name = g_strndup(names[i], strcspn(names[i], "="));
            env = g_environ_unsetenv(env, name);
        }
    }
    env = g_environ_setenv(env, "PORTA_DESKTOP", "1", TRUE);
    env = g_environ_setenv(env, "PORTA_DESKTOP_TOKEN", token, TRUE);
    env = g_environ_setenv(env, "TERM", "xterm-256color", TRUE);
    env = g_environ_setenv(env, "COLORTERM", "truecolor", TRUE);
    if (system_menu) {
        env = g_environ_setenv(env, "PORTA_DESKTOP_MENU", "system", TRUE);
    }
    return env;
}

char *porta_key_sequence(guint codepoint, gboolean shift, gboolean alt, gboolean ctrl)
{
    return g_strdup_printf("\x1b[%u;%du", codepoint, 1 + (shift ? 1 : 0) + (alt ? 2 : 0) + (ctrl ? 4 : 0));
}
