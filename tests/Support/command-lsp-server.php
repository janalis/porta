<?php

declare(strict_types=1);

// Mock server for executeCommand paths: replies null for "mock.ok" and
// then notifies "mock/ran"; replies with an error for "mock.fail".

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
        if (strlen($buffer) < ($headerEnd + 4 + $length)) {
            break;
        }
        $message = json_decode(substr($buffer, $headerEnd + 4, $length), true);
        $buffer = substr($buffer, $headerEnd + 4 + $length);

        if (!is_array($message)) {
            continue;
        }
        $method = $message['method'] ?? '';

        if ('initialize' === $method) {
            sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['capabilities' => []]]);
        } elseif ('workspace/executeCommand' === $method) {
            $command = $message['params']['command'] ?? '';
            if ('mock.fail' === $command) {
                sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32000, 'message' => 'command blew up']]);
            } else {
                sendMessage($out, ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
                sendMessage($out, ['jsonrpc' => '2.0', 'method' => 'mock/ran', 'params' => ['command' => $command]]);
            }
        } elseif ('exit' === $method) {
            exit(0);
        }
    }
}
