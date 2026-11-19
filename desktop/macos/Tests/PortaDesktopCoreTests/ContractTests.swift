import Foundation
import XCTest
@testable import PortaDesktopCore

/// The files every host and Porta itself are held to.
final class ContractTests: XCTestCase {
    private func contract(_ name: String) throws -> [String: Any] {
        let root = URL(fileURLWithPath: #filePath).deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent().deletingLastPathComponent()
        let data = try Data(contentsOf: root.appendingPathComponent("contract/\(name).json"))
        return try XCTUnwrap(JSONSerialization.jsonObject(with: data) as? [String: Any])
    }

    private func payload(_ sequence: String) -> String {
        String(sequence.dropFirst(7).dropLast(2))
    }

    func testMessagesReadAsTheContractWritesThem() throws {
        let examples = try XCTUnwrap(contract("protocol")["examples"] as? [String: String])
        let token = try XCTUnwrap(examples["token"])

        XCTAssertEqual(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["ready"])), token: token), .ready(root: examples["root"]!))
        XCTAssertEqual(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["accepted"])), token: token), .accepted)
        XCTAssertEqual(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["closing"])), token: token), .closing)
        XCTAssertEqual(DesktopMessage.closeRequest(token: token), examples["close"])
    }

    func testAnotherSessionsTokenIsNotListenedTo() throws {
        let examples = try XCTUnwrap(contract("protocol")["examples"] as? [String: String])

        XCTAssertNil(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["closing"])), token: String(repeating: "f", count: 32)))
    }

    func testForwardedChordsAreTheContractsAndSpellItsBytes() throws {
        let keys = try XCTUnwrap(contract("keys")["macosForwardedCmdChords"] as? [[String: String]])
        let expected = Set(keys.map { $0["sequence"]! })

        XCTAssertEqual(Set(KeyChord.forwarded.map(\.sequence)), expected)
        XCTAssertEqual(KeyChord.forwarded.count, keys.count)
    }
}
