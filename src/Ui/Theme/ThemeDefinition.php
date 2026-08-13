<?php

declare(strict_types=1);

namespace Porta\Ui\Theme;

use Porta\Ui\Role;
use Symfony\Component\Tui\Style\Style;

/**
 * One theme as data for the two styling paths: a stylesheet rule map
 * (selector → immutable tui {@see Style}, fed verbatim into the renderer's
 * sheet) and the SGR-58 underline-color residuals a tui Style cannot express.
 * Every {@see Role} selector is guaranteed a rule — omitted ones are filled
 * with the plain Style — so applying a theme overwrites every rule of the
 * previous one (the tui StyleSheet can only add rules, never remove them).
 */
final readonly class ThemeDefinition
{
    /** @var array<string, Style> selector → Style, total over the Role selectors */
    private array $rules;

    /** @var array<string, Style> role name → Style, pre-indexed for the render hot path */
    private array $byRole;

    /**
     * @param array<string, Style>  $rules      stylesheet selector → Style; role selectors may be omitted (filled as plain)
     * @param array<string, string> $underlines role name → SGR-58 underline-color sequence
     */
    public function __construct(
        array $rules,
        private array $underlines,
        private bool $hasColor,
    ) {
        $byRole = [];
        foreach (Role::cases() as $role) {
            $rules[$role->selector()] ??= new Style();
            $byRole[$role->name] = $rules[$role->selector()];
        }
        $this->rules = $rules;
        $this->byRole = $byRole;
    }

    /** @return array<string, Style> the full rule map for the renderer's stylesheet */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * One selector's rule, plain when the theme defines none — the element
     * rules widgets resolve through the stylesheet, reachable directly for
     * chrome drawn outside any widget (the modal shadow).
     */
    public function rule(string $selector): Style
    {
        return $this->rules[$selector] ?? new Style();
    }

    public function of(Role $role): Style
    {
        return $this->byRole[$role->name];
    }

    /** The SGR-58 underline-color sequence for $role, or '' when the theme defines none. */
    public function underline(Role $role): string
    {
        return $this->underlines[$role->name] ?? '';
    }

    public function hasColor(): bool
    {
        return $this->hasColor;
    }
}
