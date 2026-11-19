/* What the Linux host decides without a window: everything here is unit tested. */
#pragma once

#include <glib.h>

#define PORTA_OSC_PRIVATE 7777
#define PORTA_OSC_CLIPBOARD 52

typedef struct PortaFilter PortaFilter;

typedef struct {
    int code;
    char *payload;
} PortaOsc;

PortaFilter *porta_filter_new(void);
void porta_filter_free(PortaFilter *filter);
/* Appends the terminal's bytes to `output` and every complete private OSC to `found` (PortaOsc, freed by porta_osc_free). */
void porta_filter_consume(PortaFilter *filter, const guint8 *bytes, gsize length, GByteArray *output, GPtrArray *found);
void porta_osc_free(gpointer osc);

typedef enum {
    PORTA_MESSAGE_NONE,
    PORTA_MESSAGE_READY,
    PORTA_MESSAGE_ACCEPTED,
    PORTA_MESSAGE_CLOSING,
    PORTA_MESSAGE_MENU,
} PortaMessageKind;

/* `value` is the decoded root for READY and the decoded JSON for MENU; the caller frees it. */
PortaMessageKind porta_message_parse(const char *payload, const char *token, char **value);
char *porta_close_request(const char *token);
char *porta_menu_choice(const char *token, const char *generation, int menu, int item);

typedef enum {
    PORTA_STEP_CLOSE_NOW,
    PORTA_STEP_ASK,
    PORTA_STEP_WAIT,
    PORTA_STEP_STOP_WAITING,
    PORTA_STEP_WAIT_FOR_EXIT,
    PORTA_STEP_FORCE_QUIT,
} PortaStep;

typedef struct {
    int state;
    gboolean was_ready;
} PortaCloseFlow;

PortaStep porta_flow_close_requested(PortaCloseFlow *flow);
PortaStep porta_flow_received(PortaCloseFlow *flow, PortaMessageKind message);
PortaStep porta_flow_timed_out(PortaCloseFlow *flow);
gboolean porta_flow_is_closing(const PortaCloseFlow *flow);
/* `exited` is false when a signal ended Porta. */
gboolean porta_flow_closes_on_exit(const PortaCloseFlow *flow, gboolean exited, int status);

char *porta_new_token(void);
char **porta_launch_argv(const char *runtime, const char *project);
char **porta_launch_env(char **inherited, const char *token, gboolean system_menu);

/* CSI-u for a chord: lowercase codepoint and modifiers. Porta's parser reads no other spelling. */
char *porta_key_sequence(guint codepoint, gboolean shift, gboolean alt, gboolean ctrl);
