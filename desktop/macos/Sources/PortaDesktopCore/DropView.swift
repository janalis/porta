import AppKit

/// Holds the terminal and takes the files and folders dropped on the window.
public final class DropView: NSView {
    public var onDropped: (([String]) -> Void)?

    public init(holding content: NSView) {
        super.init(frame: content.frame)
        content.autoresizingMask = [.width, .height]
        content.frame = bounds
        addSubview(content)
        registerForDraggedTypes([.fileURL])
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) {
        fatalError("built in code")
    }

    override public func draggingEntered(_ sender: NSDraggingInfo) -> NSDragOperation {
        Self.paths(on: sender.draggingPasteboard).isEmpty ? [] : .generic
    }

    override public func performDragOperation(_ sender: NSDraggingInfo) -> Bool {
        let dropped = Self.paths(on: sender.draggingPasteboard)
        guard !dropped.isEmpty else { return false }
        onDropped?(dropped)
        return true
    }

    /// The files and folders on a drag's pasteboard; a dragged web link or text is none.
    public static func paths(on pasteboard: NSPasteboard) -> [String] {
        let urls = pasteboard.readObjects(forClasses: [NSURL.self], options: [.urlReadingFileURLsOnly: true]) as? [URL] ?? []
        return urls.map(\.path)
    }
}
