/// Takes Porta's private OSC out of the terminal's output, wherever the reads happened to split it.
public struct OscFilter {
    private static let escape: UInt8 = 0x1b
    private static let bell: UInt8 = 0x07
    private static let stringTerminator: UInt8 = 0x5c
    private static let longestPayload = 4 * 1024 * 1024

    private let prefix: [UInt8] = [0x1b] + Array("]\(DesktopMessage.oscCode);".utf8)
    private var matched = 0
    private var inside = false
    private var sawEscape = false
    private var payload: [UInt8] = []

    public init() {}

    /// - Returns: the bytes meant for the terminal, and every complete private payload.
    public mutating func consume(_ bytes: ArraySlice<UInt8>) -> (output: [UInt8], payloads: [String]) {
        var output: [UInt8] = []
        output.reserveCapacity(bytes.count)
        var payloads: [String] = []
        for byte in bytes {
            if inside {
                readPayload(byte, into: &payloads, passing: &output)
            } else {
                matchPrefix(byte, passing: &output)
            }
        }
        return (output, payloads)
    }

    private mutating func matchPrefix(_ byte: UInt8, passing output: inout [UInt8]) {
        if byte == prefix[matched] {
            matched += 1
            if matched == prefix.count {
                matched = 0
                inside = true
            }
            return
        }
        output.append(contentsOf: prefix[0..<matched])
        matched = byte == Self.escape ? 1 : 0
        if matched == 0 {
            output.append(byte)
        }
    }

    private mutating func readPayload(_ byte: UInt8, into payloads: inout [String], passing output: inout [UInt8]) {
        if byte == Self.bell || (sawEscape && byte == Self.stringTerminator) {
            payloads.append(String(decoding: payload, as: UTF8.self))
            reset()
            return
        }
        if sawEscape {
            payload.append(Self.escape)
            sawEscape = false
        }
        if byte == Self.escape {
            sawEscape = true
            return
        }
        payload.append(byte)
        if payload.count > Self.longestPayload {
            // Not ours after all: nothing Porta sends is this long.
            output.append(contentsOf: prefix)
            output.append(contentsOf: payload)
            reset()
        }
    }

    private mutating func reset() {
        inside = false
        sawEscape = false
        payload = []
    }
}
