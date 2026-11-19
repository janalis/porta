/// Porta's application menus, as `desktop/contract/protocol.json` describes them.
public struct MenuBar: Decodable, Equatable {
    public struct Item: Decodable, Equatable {
        public let label: String
        public let shortcut: String
        public let separator: Bool
        public let enabled: Bool
        public let checked: Bool?
    }

    public struct Menu: Decodable, Equatable {
        public let title: String
        public let items: [Item]
    }

    /// Names the menus' shape: a choice is only good for the shape it was made on.
    public let generation: String
    public let menus: [Menu]
}
