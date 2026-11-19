/// Takes Porta's private OSC out of the terminal's output, wherever the reads happened to split it.
public struct OscFilter {
    private static let escape: UInt8 = 0x1b
    private static let bell: UInt8 = 0x07
    private static let stringTerminator: UInt8 = 0x5c
    private static let longestPayload = 4 * 1024 * 1024
    /// `ESC ]`, `ESC P`, `ESC _`, `ESC ^`, `ESC X`: sequences that carry a string.
    private static let stringIntroducers: Set<UInt8> = [0x5D, 0x50, 0x5F, 0x5E, 0x58]

    private let prefix: [UInt8] = [0x1b] + Array("]\(DesktopMessage.oscCode);".utf8)
    private var matched = 0
    private var inside = false
    private var sawEscape = false
    /// A sequence someone else wrote into the middle of a payload, on its way to the terminal.
    private var foreign: [UInt8]?
    /// Whether that sequence is a string (OSC, DCS, APC, PM, SOS), ended by BEL or ST, rather than a CSI.
    private var foreignIsString = false
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
        if var sequence = foreign {
            sequence.append(byte)
            // A CSI runs to its final byte, 0x40 to 0x7E; a string to BEL or ST.
            let ended = foreignIsString
                ? byte == Self.bell || (byte == Self.stringTerminator && sequence.dropLast().last == Self.escape)
                : (0x40...0x7E).contains(byte)
            if ended {
                output.append(contentsOf: sequence)
                foreign = nil
            } else {
                foreign = sequence
            }
            return
        }
        if byte == Self.bell || (sawEscape && byte == Self.stringTerminator) {
            payloads.append(String(decoding: payload, as: UTF8.self))
            reset()
            return
        }
        if sawEscape {
            // Porta's payloads never hold ESC: this is another writer's sequence that
            // landed mid-payload (symfony/tui answers the kitty query unqueued).
            sawEscape = false
            if byte == UInt8(ascii: "[") || Self.stringIntroducers.contains(byte) {
                foreign = [Self.escape, byte]
                foreignIsString = byte != UInt8(ascii: "[")
            } else {
                output.append(contentsOf: [Self.escape, byte])
            }
            return
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
        foreign = nil
        payload = []
    }
}
