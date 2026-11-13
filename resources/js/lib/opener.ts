/**
 * The control that opened a dialog, so focus can go back to it on close.
 *
 * Usually the focused element. But Safari (and Firefox on macOS) do not focus
 * a button when it is clicked, so after a click the focused element is the
 * page itself: then the control last pressed is used. A key press clears it,
 * so a dialog opened by a shortcut never sends focus to an old click.
 */
let pressed: HTMLElement | null = null;

if (typeof document !== 'undefined') {
    document.addEventListener(
        'pointerdown',
        (event) => {
            const control =
                event.target instanceof Element
                    ? event.target.closest(
                          'button, a[href], input, select, textarea, [tabindex]',
                      )
                    : null;
            pressed = control instanceof HTMLElement ? control : null;
        },
        true,
    );
    document.addEventListener(
        'keydown',
        () => {
            pressed = null;
        },
        true,
    );
}

export function currentOpener(): HTMLElement | null {
    const active = document.activeElement;
    const control = pressed?.isConnected ? pressed : null;

    // A click on a button inside a focusable area (a scrolling table) can
    // focus the area instead: the button is the opener.
    if (control && (active === document.body || active?.contains(control))) {
        return control;
    }

    return active instanceof HTMLElement && active !== document.body
        ? active
        : control;
}
