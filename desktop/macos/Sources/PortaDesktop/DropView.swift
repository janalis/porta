import AppKit

/// Holds the terminal and takes the files and folders dropped on the window.
final class DropView: NSView {
    var onDropped: (([String]) -> Void)?

    init(holding content: NSView) {
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

    override func draggingEntered(_ sender: NSDraggingInfo) -> NSDragOperation {
        paths(sender).isEmpty ? [] : .generic
    }

    override func performDragOperation(_ sender: NSDraggingInfo) -> Bool {
        let dropped = paths(sender)
        guard !dropped.isEmpty else { return false }
        onDropped?(dropped)
        return true
    }

    private func paths(_ sender: NSDraggingInfo) -> [String] {
        let urls = sender.draggingPasteboard.readObjects(forClasses: [NSURL.self], options: [.urlReadingFileURLsOnly: true]) as? [URL] ?? []
        return urls.map(\.path)
    }
}
