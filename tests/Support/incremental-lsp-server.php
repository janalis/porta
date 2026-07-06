<?php

declare(strict_types=1);

/**
 * Mock LSP server that advertises *incremental* text sync (change kind 2).
 * On each didChange it publishes a diagnostic whose message reports whether
 * the client sent an incremental change (a contentChange carrying a `range`)
 * or a full-text replacement — letting the E2e suite assert the wire format.
 */

$in = fopen('php://stdin', 'rb');
$out = fopen('php://stdout', 'wb');

function sendMessage($out, array $message): void
{
    $json = json_encode($message, JSON_UNESCAPED_SLASHES);
    fwrite($out, 'Content-Length: '.strlen($json)."\r\n\r\n".$json);
    fflush($out);
}

$buffer = '';
while (!feof($in)) {
    $chunk = fread($in, 8192);
    if (false === $chunk) {
        break;
    }
    $buffer .= $chunk;

    while (true) {
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if (false === $headerEnd || !preg_match('/Content-Length:\s*(\d+)/i', substr($buffer, 0, $headerEnd), $m)) {
            break;
        }
        $length = (int) $m[1];
        $bodyStart = $headerEnd + 4;
        if (strlen($buffer) < ($bodyStart + $length)) {
            break;
        }
        $message = json_decode(substr($buffer, $bodyStart, $length), true);
        $buffer = substr($buffer, $bodyStart + $length);
        if (!is_array($message)) {
            continue;
        }

        $method = $message['method'] ?? '';

        if ('initialize' === $method) {
            sendMessage($out, [
                'jsonrpc' => '2.0',
                'id' => $message['id'],
                'result' => [
                    'capabilities' => [
                        'textDocumentSync' => ['openClose' => true, 'change' => 2],
                    ],
                    'serverInfo' => ['name' => 'incremental-lsp'],
                ],
            ]);
        } elseif ('textDocument/didChange' === $method) {
            $first = $message['params']['contentChanges'][0] ?? [];
            $kind = isset($first['range']) ? 'incremental' : 'full';
            sendMessage($out, [
                'jsonrpc' => '2.0',
                'method' => 'textDocument/publishDiagnostics',
                'params' => [
                    'uri' => $message['params']['textDocument']['uri'],
                    'diagnostics' => [[
                        'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]],
                        'severity' => 2,
                        'message' => 'sync:'.$kind.':'.($first['text'] ?? ''),
                    ]],
                ],
            ]);
        } elseif ('shutdown' === $method) {
            sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
        } elseif ('exit' === $method) {
            exit(0);
        }
    }
}
