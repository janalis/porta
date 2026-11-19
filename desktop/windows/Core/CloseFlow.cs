namespace Porta.Desktop
{
    public enum CloseStep
    {
        CloseNow,
        AskPorta,
        Wait,
        StopWaiting,
        WaitForExit,
        OfferForceQuit,
    }

    /// <summary>What the close button does, which depends on how far Porta has got.</summary>
    public sealed class CloseFlow
    {
        private const int SignalBase = 128;

        private enum State
        {
            Starting,
            Ready,
            Asking,
            Closing,
        }

        private State state = State.Starting;
        private bool wasReady;

        public bool IsClosing => state == State.Closing;

        public CloseStep CloseRequested()
        {
            switch (state)
            {
                case State.Starting:
                    return CloseStep.CloseNow;
                case State.Ready:
                    state = State.Asking;
                    return CloseStep.AskPorta;
                default:
                    return CloseStep.Wait;
            }
        }

        public CloseStep Received(MessageKind message)
        {
            switch (message)
            {
                case MessageKind.Ready:
                    state = State.Ready;
                    wasReady = true;
                    return CloseStep.Wait;
                case MessageKind.Accepted:
                    // Unsaved work: Porta has said why, and the window stays.
                    state = State.Ready;
                    return CloseStep.StopWaiting;
                case MessageKind.Closing:
                    state = State.Closing;
                    return CloseStep.WaitForExit;
                default:
                    return CloseStep.Wait;
            }
        }

        public CloseStep AnswerTimedOut()
        {
            if (state == State.Closing)
            {
                return CloseStep.OfferForceQuit;
            }

            if (state != State.Asking)
            {
                return CloseStep.Wait;
            }

            state = State.Ready;
            return CloseStep.OfferForceQuit;
        }

        /// <summary>A failure to start stays on screen: what it printed is the only explanation.</summary>
        public bool ClosesWhenPortaExits(int status) => status == 0 || (wasReady && status > SignalBase);
    }
}
