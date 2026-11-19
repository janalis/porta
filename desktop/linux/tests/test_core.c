#include "../src/core.h"

#include <json-glib/json-glib.h>

#include <string.h>

/* The contract's example named `key`, with its JSON escapes undone. */
static char *example(const char *key)
{
    g_autofree char *contents = NULL;
    g_assert_true(g_file_get_contents(CONTRACT_DIR "/protocol.json", &contents, NULL, NULL));
    g_autofree char *pattern = g_strdup_printf("\"%s\": \"((?:[^\"\\\\]|\\\\.)*)\"", key);
    g_autoptr(GRegex) regex = g_regex_new(pattern, 0, 0, NULL);
    g_autoptr(GMatchInfo) match = NULL;
    g_assert_true(g_regex_match(regex, strstr(contents, "\"examples\""), 0, &match));
    g_autofree char *raw = g_match_info_fetch(match, 1);
    g_autoptr(GRegex) escape = g_regex_new("\\\\u001b", 0, 0, NULL);
    g_autofree char *unescaped = g_regex_replace_literal(escape, raw, -1, 0, "\x1b", 0, NULL);
    g_autoptr(GRegex) backslash = g_regex_new("\\\\\\\\", 0, 0, NULL);
    return g_regex_replace_literal(backslash, unescaped, -1, 0, "\\", 0, NULL);
}

/* The sequence's payload: past `ESC ] 7777 ;`, before `ESC \`. */
static char *payload(const char *sequence)
{
    return g_strndup(sequence + 7, strlen(sequence) - 9);
}

static void test_messages_read_as_the_contract_writes_them(void)
{
    g_autofree char *token = example("token");
    g_autofree char *ready = example("ready");
    g_autofree char *ready_payload = payload(ready);
    g_autofree char *closing = example("closing");
    g_autofree char *closing_payload = payload(closing);
    g_autofree char *close = example("close");
    g_autofree char *request = porta_close_request(token);
    g_autofree char *root = NULL;
    g_autofree char *nothing = NULL;

    g_assert_cmpint(porta_message_parse(ready_payload, token, &root), ==, PORTA_MESSAGE_READY);
    g_assert_cmpstr(root, ==, "/work/shop");
    g_assert_cmpint(porta_message_parse(closing_payload, token, &nothing), ==, PORTA_MESSAGE_CLOSING);
    g_assert_cmpstr(request, ==, close);
}

static void test_another_sessions_token_is_not_listened_to(void)
{
    g_autofree char *closing = example("closing");
    g_autofree char *closing_payload = payload(closing);
    g_autofree char *nothing = NULL;

    g_assert_cmpint(porta_message_parse(closing_payload, "ffffffffffffffffffffffffffffffff", &nothing), ==, PORTA_MESSAGE_NONE);
}

static void test_the_menu_choice_is_the_contracts(void)
{
    g_autofree char *token = example("token");
    g_autofree char *expected = example("menuChoice");
    g_autofree char *choice = porta_menu_choice(token, "abc123", 0, 2);

    g_assert_cmpstr(choice, ==, expected);
}

static void run_filter(const char *first, gsize first_length, const char *second, GByteArray *output, GPtrArray *found)
{
    PortaFilter *filter = porta_filter_new();
    porta_filter_consume(filter, (const guint8 *) first, first_length, output, found);
    porta_filter_consume(filter, (const guint8 *) second, strlen(second), output, found);
    porta_filter_free(filter);
}

static void test_the_newer_messages_are_the_contracts(void)
{
    g_autofree char *token = example("token");
    g_autofree char *path = example("openPath");
    g_autofree char *open = example("open");
    g_autofree char *appearance = example("appearance");
    g_autofree char *state = example("state");
    g_autofree char *state_payload = payload(state);
    g_autofree char *request = porta_open_request(token, path);
    g_autofree char *dark = porta_appearance(token, TRUE);
    g_autofree char *count = NULL;
    g_autofree char *nothing = NULL;
    g_autofree char *bad = g_strdup_printf("porta-desktop;%s;state;-1", token);

    g_assert_cmpstr(request, ==, open);
    g_assert_cmpstr(dark, ==, appearance);
    g_assert_cmpint(porta_message_parse(state_payload, token, &count), ==, PORTA_MESSAGE_STATE);
    g_assert_cmpstr(count, ==, "2");
    g_assert_cmpint(porta_message_parse(bad, token, &nothing), ==, PORTA_MESSAGE_NONE);

    g_autofree char *notify = example("notify");
    g_autofree char *notify_payload = payload(notify);
    g_autofree char *notify_text = example("notifyText");
    g_autofree char *text = NULL;
    g_assert_cmpint(porta_message_parse(notify_payload, token, &text), ==, PORTA_MESSAGE_NOTIFY);
    g_assert_cmpstr(text, ==, notify_text);
}

static JsonObject *font_choice(JsonParser *parser)
{
    g_assert_true(json_parser_load_from_file(parser, CONTRACT_DIR "/font-choice.json", NULL));
    return json_node_get_object(json_parser_get_root(parser));
}

static void test_a_chosen_size_is_written_as_the_contract_says(void)
{
    g_autoptr(JsonParser) parser = json_parser_new();
    JsonArray *sizes = json_object_get_array_member(font_choice(parser), "sizes");

    for (guint i = 0; i < json_array_get_length(sizes); i++) {
        JsonArray *pair = json_array_get_array_element(sizes, i);
        double chosen = g_ascii_strtod(json_array_get_string_element(pair, 0), NULL);
        double written = g_ascii_strtod(json_array_get_string_element(pair, 1), NULL);
        g_assert_cmpfloat(porta_font_size_written(chosen), ==, written);
    }
}

static void test_a_chosen_font_keeps_the_rest_of_the_file(void)
{
    g_autoptr(JsonParser) contract = json_parser_new();
    const char *existing = json_object_get_string_member(font_choice(contract), "existing");
    g_autofree char *written = porta_settings_with_font(existing, "JetBrains Mono", 14.6);
    g_autoptr(JsonParser) parser = json_parser_new();

    g_assert_true(json_parser_load_from_data(parser, written, -1, NULL));
    JsonObject *settings = json_node_get_object(json_parser_get_root(parser));
    g_assert_cmpstr(json_object_get_string_member(settings, "fontFamily"), ==, "JetBrains Mono");
    g_assert_cmpfloat(json_object_get_double_member(settings, "fontSize"), ==, 15);
    JsonArray *other = json_object_get_array_member(settings, "other");
    g_assert_cmpint(json_array_get_int_element(other, 0), ==, 1);
    g_assert_true(json_object_get_boolean_member(json_array_get_object_element(other, 1), "deep"));

    for (guint i = 0; i < 3; i++) {
        const char *broken[] = {NULL, "not json", "[1]"};
        g_autofree char *fresh = porta_settings_with_font(broken[i], "Menlo", 13);
        g_autoptr(JsonParser) reread = json_parser_new();
        g_assert_true(json_parser_load_from_data(reread, fresh, -1, NULL));
        g_assert_cmpstr(json_object_get_string_member(json_node_get_object(json_parser_get_root(reread)), "fontFamily"), ==, "Menlo");
    }
}

static void test_a_split_at_every_byte_changes_nothing(void)
{
    const char *whole = "a\x1b]7777;porta-desktop;t;closing\x1b\\" "b";

    for (gsize cut = 0; cut <= strlen(whole); cut++) {
        g_autoptr(GByteArray) output = g_byte_array_new();
        g_autoptr(GPtrArray) found = g_ptr_array_new_with_free_func(porta_osc_free);
        run_filter(whole, cut, whole + cut, output, found);

        g_assert_cmpmem(output->data, output->len, "ab", 2);
        g_assert_cmpuint(found->len, ==, 1);
        g_assert_cmpstr(((PortaOsc *) found->pdata[0])->payload, ==, "porta-desktop;t;closing");
    }
}

static void test_the_clipboard_is_caught_and_other_escapes_pass(void)
{
    const char *others = "\x1b[31mred\x1b]0;title\x07\x1b]777;x\x07\x1b\x1b[0m";
    g_autoptr(GByteArray) output = g_byte_array_new();
    g_autoptr(GPtrArray) found = g_ptr_array_new_with_free_func(porta_osc_free);
    g_autofree char *input = g_strconcat(others, "\x1b]52;c;aGk=\x07", NULL);

    run_filter(input, strlen(input), "", output, found);

    g_assert_cmpmem(output->data, output->len, others, strlen(others));
    g_assert_cmpuint(found->len, ==, 1);
    g_assert_cmpint(((PortaOsc *) found->pdata[0])->code, ==, PORTA_OSC_CLIPBOARD);
    g_assert_cmpstr(((PortaOsc *) found->pdata[0])->payload, ==, "c;aGk=");
}

static void test_a_sequence_written_into_the_payload_goes_to_the_terminal(void)
{
    g_autoptr(GByteArray) output = g_byte_array_new();
    g_autoptr(GPtrArray) found = g_ptr_array_new_with_free_func(porta_osc_free);
    const char *first = "x\x1b]7777;abc\x1b[>7";

    run_filter(first, strlen(first), "ude\x1bMf\x1b\\y", output, found);

    g_assert_cmpuint(found->len, ==, 1);
    g_assert_cmpstr(((PortaOsc *) found->pdata[0])->payload, ==, "abcdef");
    g_assert_cmpmem(output->data, output->len, "x\x1b[>7u\x1bMy", 9);
}

static void test_a_string_sequence_written_into_the_payload_goes_whole_to_the_terminal(void)
{
    g_autoptr(GByteArray) output = g_byte_array_new();
    g_autoptr(GPtrArray) found = g_ptr_array_new_with_free_func(porta_osc_free);
    const char *input = "\x1b]7777;ab\x1b_Ga=T;AAAA\x1b\\cd\x1b]0;title\x07" "ef\x1b\\";

    run_filter(input, strlen(input), "", output, found);

    g_assert_cmpuint(found->len, ==, 1);
    g_assert_cmpstr(((PortaOsc *) found->pdata[0])->payload, ==, "abcdef");
    g_assert_cmpmem(output->data, output->len, "\x1b_Ga=T;AAAA\x1b\\\x1b]0;title\x07", 23);
}

static void test_the_close_flow(void)
{
    PortaCloseFlow flow = {0};

    g_assert_cmpint(porta_flow_close_requested(&flow), ==, PORTA_STEP_CLOSE_NOW);
    g_assert_false(porta_flow_closes_on_exit(&flow, FALSE, 0));
    porta_flow_received(&flow, PORTA_MESSAGE_READY);
    g_assert_cmpint(porta_flow_close_requested(&flow), ==, PORTA_STEP_ASK);
    g_assert_cmpint(porta_flow_close_requested(&flow), ==, PORTA_STEP_WAIT);
    g_assert_cmpint(porta_flow_received(&flow, PORTA_MESSAGE_ACCEPTED), ==, PORTA_STEP_STOP_WAITING);
    g_assert_cmpint(porta_flow_close_requested(&flow), ==, PORTA_STEP_ASK);
    g_assert_cmpint(porta_flow_timed_out(&flow), ==, PORTA_STEP_FORCE_QUIT);
    g_assert_cmpint(porta_flow_timed_out(&flow), ==, PORTA_STEP_WAIT);
    g_assert_true(porta_flow_closes_on_exit(&flow, FALSE, 0));
    g_assert_true(porta_flow_closes_on_exit(&flow, TRUE, 0));
    /* A shutdown that hangs still leaves a way out. */
    g_assert_cmpint(porta_flow_close_requested(&flow), ==, PORTA_STEP_ASK);
    g_assert_cmpint(porta_flow_received(&flow, PORTA_MESSAGE_CLOSING), ==, PORTA_STEP_WAIT_FOR_EXIT);
    g_assert_true(porta_flow_is_closing(&flow));
    g_assert_cmpint(porta_flow_close_requested(&flow), ==, PORTA_STEP_WAIT);
    g_assert_cmpint(porta_flow_timed_out(&flow), ==, PORTA_STEP_FORCE_QUIT);
    g_assert_cmpint(porta_flow_timed_out(&flow), ==, PORTA_STEP_FORCE_QUIT);
    g_assert_false(porta_flow_closes_on_exit(&flow, TRUE, 1));
}

static void test_the_launch(void)
{
    g_auto(GStrv) argv = porta_launch_argv("/usr/lib/porta-desktop/runtime", "/work/shop");
    char *inherited[] = {"PATH=/bin", "PHPRC=/x", "LD_PRELOAD=/y", "TMUX=1", "TERM=dumb", NULL};
    g_auto(GStrv) env = porta_launch_env(inherited, "tok", TRUE);

    g_assert_cmpstr(argv[0], ==, "/usr/lib/porta-desktop/runtime/php");
    g_assert_cmpstr(argv[1], ==, "-n");
    g_assert_cmpstr(argv[6], ==, "/usr/lib/porta-desktop/runtime/porta.phar");
    g_assert_cmpstr(argv[7], ==, "/work/shop");
    g_assert_cmpstr(g_environ_getenv(env, "PATH"), ==, "/bin");
    g_assert_null(g_environ_getenv(env, "PHPRC"));
    g_assert_null(g_environ_getenv(env, "LD_PRELOAD"));
    g_assert_null(g_environ_getenv(env, "TMUX"));
    g_assert_cmpstr(g_environ_getenv(env, "TERM"), ==, "xterm-256color");
    g_assert_cmpstr(g_environ_getenv(env, "PORTA_DESKTOP"), ==, "1");
    g_assert_cmpstr(g_environ_getenv(env, "PORTA_DESKTOP_TOKEN"), ==, "tok");
    g_assert_cmpstr(g_environ_getenv(env, "PORTA_DESKTOP_MENU"), ==, "system");
}

static void test_no_system_menu_is_claimed_without_one(void)
{
    char *inherited[] = {"PORTA_DESKTOP_MENU=system", NULL};
    g_auto(GStrv) env = porta_launch_env(inherited, "tok", FALSE);

    g_assert_null(g_environ_getenv(env, "PORTA_DESKTOP_MENU"));
}

static void test_tokens_and_keys(void)
{
    g_autofree char *token = porta_new_token();
    g_autofree char *chord = porta_key_sequence('v', TRUE, FALSE, TRUE);

    g_assert_true(g_regex_match_simple("^[0-9a-f]{32}$", token, 0, 0));
    g_assert_cmpstr(chord, ==, "\x1b[118;6u");
}

int main(int argc, char **argv)
{
    g_test_init(&argc, &argv, NULL);
    g_test_add_func("/messages/contract", test_messages_read_as_the_contract_writes_them);
    g_test_add_func("/messages/token", test_another_sessions_token_is_not_listened_to);
    g_test_add_func("/messages/menu-choice", test_the_menu_choice_is_the_contracts);
    g_test_add_func("/messages/newer", test_the_newer_messages_are_the_contracts);
    g_test_add_func("/font/size", test_a_chosen_size_is_written_as_the_contract_says);
    g_test_add_func("/font/kept", test_a_chosen_font_keeps_the_rest_of_the_file);
    g_test_add_func("/filter/split", test_a_split_at_every_byte_changes_nothing);
    g_test_add_func("/filter/foreign", test_a_sequence_written_into_the_payload_goes_to_the_terminal);
    g_test_add_func("/filter/foreign-string", test_a_string_sequence_written_into_the_payload_goes_whole_to_the_terminal);
    g_test_add_func("/filter/clipboard", test_the_clipboard_is_caught_and_other_escapes_pass);
    g_test_add_func("/flow/close", test_the_close_flow);
    g_test_add_func("/launch/plan", test_the_launch);
    g_test_add_func("/launch/menu", test_no_system_menu_is_claimed_without_one);
    g_test_add_func("/misc/tokens-and-keys", test_tokens_and_keys);
    return g_test_run();
}
