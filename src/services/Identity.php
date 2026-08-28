<?php

namespace justinholtweb\bee\services;

use Craft;
use craft\elements\User as UserElement;
use craft\helpers\StringHelper;
use craft\web\Request as WebRequest;
use justinholtweb\bee\errors\ApiException;
use justinholtweb\bee\helpers\Ids;
use justinholtweb\bee\Plugin;
use yii\base\Component;
use yii\web\Cookie;

/**
 * Who Recombee thinks it is looking at.
 *
 * Recommendation quality is a function of history, and history is a function of a stable ID. The
 * awkward part is that most of a visitor's history happens before they have an account:
 *
 *   - signed in  → `u{user uid}`, stable forever
 *   - guest      → `g{token}`, held in a cookie
 *   - guest signs in → the two are merged in Recombee, so the browsing that led to the signup is
 *     not thrown away at the exact moment it becomes most useful
 *
 * The ID is *always* derived here, server-side. It is never read from a request body, because the
 * tracking endpoint is public: a caller who could name the user could write interactions into
 * someone else's profile, or read a stranger's recommendations.
 */
class Identity extends Component
{
    private ?string $resolved = null;

    private bool $resolvedIsSet = false;

    /**
     * A guest token minted during this request.
     *
     * Craft signs its cookies, so a cookie added to the *response* cannot be read back off the
     * *request* in the same cycle — and reading `$_COOKIE` directly would fail signature
     * validation. Without this memo, the first page view of a session would be tracked under a
     * token the next page never uses, quietly orphaning every new visitor's first interaction.
     */
    private ?string $mintedToken = null;

    /**
     * The Recombee user ID for this request, minting a guest token if needed.
     *
     * Returns null when nobody should be tracked: tracking off, no consent, a console request, or
     * a guest when guest tracking is disabled.
     */
    public function currentUserId(bool $create = true): ?string
    {
        if ($this->resolvedIsSet) {
            return $this->resolved;
        }

        $this->resolvedIsSet = true;

        return $this->resolved = $this->resolve($create);
    }

    /**
     * The ID for a specific Craft user — used by the console and by Commerce, which can be running
     * long after the customer's request ended.
     */
    public function userIdFor(?UserElement $user): ?string
    {
        return $user !== null ? Ids::forUser($user) : null;
    }

    /**
     * Whether Bee may track this visitor at all.
     *
     * Unknown consent is *not* consent. A visitor who has not answered the banner yet has not said
     * yes, and an interaction sent now cannot be recalled when they say no.
     */
    public function hasConsent(): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->consentMode === $settings::CONSENT_ALWAYS) {
            return true;
        }

        $name = trim($settings->consentCookieName);

        if ($name === '') {
            // Consent gating was asked for but never configured. Failing closed is the only safe
            // reading: the merchant's intent was clearly "do not track without consent".
            return false;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest) {
            return false;
        }

        $value = $request->getCookies()->getValue($name);

        if ($value === null) {
            // Craft signs the cookies it sets. A CMP's cookie is not one of ours, so it has to be
            // read raw as well — otherwise consent granted by the banner is invisible to Bee.
            $value = $_COOKIE[$name] ?? null;
        }

        if ($value === null) {
            return false;
        }

        $needle = strtolower(trim((string)$value));

        foreach ($settings->consentCookieValueList() as $accepted) {
            if ($needle === $accepted || str_contains($needle, $accepted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge a guest's Recombee history into a signed-in user's.
     *
     * `cascadeCreate` matters here: the target user very often does not exist in Recombee yet,
     * because they have never interacted with anything *as* themselves. Without it the merge 404s
     * and the entire pre-signup history is stranded.
     */
    public function merge(string $sourceUserId, string $targetUserId): bool
    {
        if ($sourceUserId === $targetUserId) {
            return false;
        }

        try {
            Plugin::getInstance()->getClient()->put(
                sprintf('users/%s/merge/%s', rawurlencode($targetUserId), rawurlencode($sourceUserId)),
                [],
                ['cascadeCreate' => 'true'],
                ['label' => 'merge ' . $sourceUserId . ' → ' . $targetUserId],
            );

            return true;
        } catch (ApiException $e) {
            // Nothing to merge is the common case for a visitor who signed up on their first page.
            if ($e->isClientError()) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Called on login. Moves the guest history over and retires the guest token.
     */
    public function handleLogin(UserElement $user): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->mergeGuestsOnLogin || !Plugin::getInstance()->isPro() || !$settings->isConfigured()) {
            return;
        }

        $token = $this->readGuestToken();

        if ($token === null) {
            return;
        }

        try {
            $this->merge(Ids::forGuest($token), Ids::forUser($user));
        } catch (\Throwable $e) {
            // Signing in must never fail because a recommender was unreachable.
            Craft::warning('Bee could not merge the guest profile on login: ' . $e->getMessage(), 'bee');
        }

        // The guest ID has been folded in; keeping the cookie would split the next session back out.
        $this->clearGuestToken();
        $this->resolvedIsSet = false;
    }

    /**
     * Forget this visitor locally. The Recombee-side deletion is a separate, deliberate act —
     * see `bee/users/forget`.
     */
    public function clearGuestToken(): void
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest) {
            return;
        }

        $name = Plugin::getInstance()->getSettings()->guestCookieName;
        Craft::$app->getResponse()->getCookies()->remove($name);
        $this->mintedToken = null;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function resolve(bool $create): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->trackingEnabled || !$settings->isConfigured()) {
            return null;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest) {
            return null;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user !== null) {
            return Ids::forUser($user);
        }

        if (!$settings->trackGuests || !$this->hasConsent()) {
            return null;
        }

        $token = $this->readGuestToken();

        if ($token === null) {
            if (!$create) {
                return null;
            }

            $token = $this->mintGuestToken();
        }

        return Ids::forGuest($token);
    }

    private function readGuestToken(): ?string
    {
        if ($this->mintedToken !== null) {
            return $this->mintedToken;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest) {
            return null;
        }

        $value = $request->getCookies()->getValue(Plugin::getInstance()->getSettings()->guestCookieName);

        // Only accept the shape Bee mints. A cookie is visitor-controlled input, and this string
        // ends up in a Recombee URL path.
        return is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) ? $value : null;
    }

    private function mintGuestToken(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $token = str_replace('-', '', StringHelper::UUID());

        $cookie = Craft::cookieConfig([
            'name' => $settings->guestCookieName,
            'value' => $token,
            'expire' => time() + ($settings->guestCookieDays * 86400),
            'httpOnly' => true,
            'sameSite' => Cookie::SAME_SITE_LAX,
        ]);

        Craft::$app->getResponse()->getCookies()->add(new Cookie($cookie));

        return $this->mintedToken = $token;
    }
}
