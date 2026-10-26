<?php

namespace App\Filament\Source\Pages\Auth;

use App\Filament\Pages\Auth\RemembersByDefault;
use App\Models\Source;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/**
 * Sources sign in with whichever contact the admin recorded for them — a mobile
 * number or an email. One field takes both: anything shaped like an email is
 * matched against `email`, anything else is normalised to bare digits and
 * matched against `mobile`.
 */
class SourceLogin extends BaseLogin
{
    use RemembersByDefault;

    public function getTitle(): string|Htmlable
    {
        return 'Sign in';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Your IPO account';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'See what was applied with your money, and what you are owed.';
    }

    /** Stamps the sign-in so the admin can see who has actually been using the portal. */
    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response !== null) {
            auth('source')->user()?->forceFill(['last_login_at' => now()])->saveQuietly();
        }

        return $response;
    }

    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getLoginFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getRememberFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getLoginFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Mobile number or Email')
            ->placeholder('98765 43210')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    public function form(Form $form): Form
    {
        return $form;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $login = trim((string) $data['login']);

        $credentials = str_contains($login, '@')
            ? ['email' => $login]
            : ['mobile' => Source::normaliseMobile($login)];

        return [
            ...$credentials,
            'password' => $data['password'],
            // A disabled or deactivated source should fail at the credential
            // stage, not merely be bounced by canAccessPanel() afterwards.
            'portal_enabled' => true,
            'is_active' => true,
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => 'These details do not match our records.',
        ]);
    }
}
