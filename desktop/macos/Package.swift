// swift-tools-version:6.2
import PackageDescription

let package = Package(
    name: "PortaDesktop",
    platforms: [.macOS(.v13)],
    dependencies: [
        .package(url: "https://github.com/migueldeicaza/SwiftTerm.git", exact: "1.99.3"),
    ],
    targets: [
        .target(name: "PortaDesktopCore", swiftSettings: [.swiftLanguageMode(.v5)]),
        .executableTarget(
            name: "PortaDesktop",
            dependencies: ["PortaDesktopCore", .product(name: "SwiftTerm", package: "SwiftTerm")],
            swiftSettings: [.swiftLanguageMode(.v5)]
        ),
        .testTarget(name: "PortaDesktopCoreTests", dependencies: ["PortaDesktopCore"], swiftSettings: [.swiftLanguageMode(.v5)]),
    ]
)
