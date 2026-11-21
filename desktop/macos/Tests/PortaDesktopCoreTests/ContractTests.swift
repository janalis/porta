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

    private func strings() throws -> [String: String] {
        try XCTUnwrap(contract("protocol")["examples"] as? [String: Any]).compactMapValues { $0 as? String }
    }

    private func payload(_ sequence: String) -> String {
        String(sequence.dropFirst(7).dropLast(2))
    }

    func testMessagesReadAsTheContractWritesThem() throws {
        let examples = try strings()
        let token = try XCTUnwrap(examples["token"])

        XCTAssertEqual(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["ready"])), token: token), .ready(root: examples["root"]!))
        XCTAssertEqual(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["accepted"])), token: token), .accepted)
        XCTAssertEqual(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["closing"])), token: token), .closing)
        XCTAssertEqual(DesktopMessage.closeRequest(token: token), examples["close"])
    }

    func testTheMenusReadAsTheContractWritesThem() throws {
        let examples = try strings()
        let message = DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["menu"])), token: try XCTUnwrap(examples["token"]))

        guard case let .menu(bar) = message else {
            return XCTFail("not a menu message")
        }
        XCTAssertEqual(bar.generation, "abc123")
        XCTAssertEqual(bar.menus.first?.title, "File")
        XCTAssertEqual(bar.menus.first?.items.map(\.label), ["Save", "", "Word Wrap"])
        XCTAssertEqual(bar.menus.first?.items[0].shortcut, "Ctrl+S")
        XCTAssertEqual(bar.menus.first?.items[1].separator, true)
        XCTAssertEqual(bar.menus.first?.items[2].enabled, false)
        XCTAssertEqual(bar.menus.first?.items[2].checked, true)
        XCTAssertNil(bar.menus.first?.items[0].checked)
        XCTAssertEqual(DesktopMessage.menuChoice(token: try XCTUnwrap(examples["token"]), generation: "abc123", menu: 0, item: 2), examples["menuChoice"])
    }

    func testTheNewerMessagesAreTheContracts() throws {
        let examples = try strings()
        let token = try XCTUnwrap(examples["token"])

        XCTAssertEqual(DesktopMessage.openRequest(token: token, path: try XCTUnwrap(examples["openPath"])), examples["open"])
        XCTAssertEqual(DesktopMessage.appearance(token: token, dark: true), examples["appearance"])
        XCTAssertEqual(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["state"])), token: token), .state(unsaved: 2))
        XCTAssertNil(DesktopMessage.parse(payload: "porta-desktop;\(token);state;-1", token: token))
        XCTAssertEqual(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["notify"])), token: token), .notify(text: try XCTUnwrap(examples["notifyText"])))
        XCTAssertNil(DesktopMessage.parse(payload: "porta-desktop;\(token);notify;not base64!", token: token))
    }

    func testThePickersMessagesAreTheContracts() throws {
        let examples = try strings()
        let token = try XCTUnwrap(examples["token"])

        XCTAssertEqual(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["picking"])), token: token), .picking)
        XCTAssertEqual(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["chooseFolder"])), token: token), .chooseFolder)
        XCTAssertNil(DesktopMessage.parse(payload: "porta-desktop;\(token);picking;extra", token: token))
    }

    func testTheLaunchSetsTheVariablesTheContractNames() throws {
        let names = try XCTUnwrap(contract("protocol")["environment"] as? [String: String])
        let variables = Set(LaunchPlan(runtime: "/r", project: nil, inherited: [:], token: "t", home: "/h", dark: true).environment.map { String($0.split(separator: "=")[0]) })

        XCTAssertTrue(Set(names.values).isSubset(of: variables), "every variable the contract names is set")
    }

    func testAnotherSessionsTokenIsNotListenedTo() throws {
        let examples = try strings()

        XCTAssertNil(DesktopMessage.parse(payload: payload(try XCTUnwrap(examples["closing"])), token: String(repeating: "f", count: 32)))
    }

    func testForwardedChordsAreTheContractsAndSpellItsBytes() throws {
        let keys = try XCTUnwrap(contract("keys")["macosForwardedCmdChords"] as? [[String: String]])
        let expected = Set(keys.map { $0["sequence"]! })

        XCTAssertEqual(Set(KeyChord.forwarded.map(\.sequence)), expected)
        XCTAssertEqual(KeyChord.forwarded.count, keys.count)
    }
}
