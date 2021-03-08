<?php

namespace jamesedmonston\graphqlauthentication\services;

use Craft;
use craft\base\Component;
use craft\controllers\GraphqlController;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\models\GqlToken;
use craft\records\GqlToken as RecordsGqlToken;
use craft\services\Gql;
use DateTime;
use DateTimeImmutable;
use GraphQL\Type\Definition\Type;
use jamesedmonston\graphqlauthentication\elements\RefreshToken;
use jamesedmonston\graphqlauthentication\events\JwtCreateEvent;
use jamesedmonston\graphqlauthentication\events\JwtValidateEvent;
use jamesedmonston\graphqlauthentication\gql\Auth;
use jamesedmonston\graphqlauthentication\GraphqlAuthentication;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Throwable;
use yii\base\Event;
use yii\base\InvalidArgumentException;

class TokenService extends Component
{
    /**
     * @event JwtCreateEvent The event that is triggered before creating a JWT.
     *
     * Plugins get a chance to add additional claims to the JWT.
     *
     * ---
     * ```php
     * use jamesedmonston\graphqlauthentication\events\JwtCreateEvent;
     * use jamesedmonston\graphqlauthentication\services\TokenService;
     * use yii\base\Event;
     *
     * Event::on(
     *     TokenService::class,
     *     TokenService::EVENT_BEFORE_CREATE_JWT,
     *     function(JwtCreateEvent $event) {
     *         $builder = $event->builder;
     *         $user = $event->user;
     *         $builder->withClaim('customClaim', 'customValue');
     *     }
     * );
     * ```
     */
    const EVENT_BEFORE_CREATE_JWT = 'beforeCreateJwt';

    /**
     * @event JwtValidateEvent The event that is triggered before validating a JWT.
     *
     * Plugins get a chance to add additional validators to the JWT verification.
     *
     * ---
     * ```php
     * use jamesedmonston\graphqlauthentication\events\JwtValidateEvent;
     * use jamesedmonston\graphqlauthentication\services\TokenService;
     * use Lcobucci\JWT\Validation\Constraint\IssuedBy;
     * use yii\base\Event;
     *
     * Event::on(
     *     TokenService::class,
     *     TokenService::EVENT_BEFORE_VALIDATE_JWT,
     *     function(JwtValidateEvent $event) {
     *         $config = $event->config;
     *         $validator = new IssuedBy('Custom Validator');
     *         $config->setValidationConstraints($validator);
     *     }
     * );
     * ```
     */
    const EVENT_BEFORE_VALIDATE_JWT = 'beforeValidateJwt';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        Event::on(
            GraphqlController::class,
            GraphqlController::EVENT_BEFORE_ACTION,
            [$this, 'rewriteJwtHeader']
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_MUTATIONS,
            [$this, 'registerGqlMutations']
        );
    }

    public function registerGqlMutations(Event $event)
    {
        $settings = GraphqlAuthentication::$plugin->getSettings();
        $userService = GraphqlAuthentication::$plugin->getInstance()->user;
        $errorService = GraphqlAuthentication::$plugin->getInstance()->error;

        $event->mutations['refreshToken'] = [
            'description' => "Refreshes a user's JWT. Checks for the occurrence of the `gql_refreshToken` cookie, and falls back to `refreshToken` argument.",
            'type' => Type::nonNull(Auth::getType()),
            'args' => [
                'refreshToken' => Type::string(),
            ],
            'resolve' => function ($source, array $arguments) use ($settings, $userService, $errorService) {
                $refreshToken = $_COOKIE['gql_refreshToken'] ?? $arguments['refreshToken'] ?? null;

                if (!$refreshToken) {
                    $errorService->throw($settings->invalidRefreshToken, 'INVALID');
                }

                $this->_clearExpiredTokens();
                $refreshTokenElement = RefreshToken::find()->where(['token' => $refreshToken])->one();

                if (!$refreshTokenElement) {
                    $errorService->throw($settings->invalidRefreshToken, 'INVALID');
                }

                $user = Craft::$app->getUsers()->getUserById($refreshTokenElement->userId);

                if (!$user) {
                    $errorService->throw($settings->userNotFound, 'INVALID');
                }

                $schemaId = $refreshTokenElement->schemaId;

                if (!$schemaId) {
                    $errorService->throw($settings->invalidSchema, 'INVALID');
                }

                Craft::$app->getElements()->deleteElementById($refreshTokenElement->id);
                $token = $this->create($user, $schemaId);
                return $userService->getResponseFields($user, $schemaId, $token);
            },
        ];
    }

    public function getHeaderToken(): GqlToken
    {
        $request = Craft::$app->getRequest();
        $requestHeaders = $request->getHeaders();
        $settings = GraphqlAuthentication::$plugin->getSettings();
        $errorService = GraphqlAuthentication::$plugin->getInstance()->error;

        foreach ($requestHeaders->get('authorization', [], false) as $authHeader) {
            $authValues = array_map('trim', explode(',', $authHeader));

            foreach ($authValues as $authValue) {
                if (preg_match('/^Bearer\s+(.+)$/i', $authValue, $matches)) {
                    try {
                        $token = Craft::$app->getGql()->getTokenByAccessToken($matches[1]);
                    } catch (InvalidArgumentException $e) {
                        $errorService->throw($e, 'INVALID');
                    }

                    if (!$token) {
                        $errorService->throw($settings->invalidHeader, 'FORBIDDEN');
                    }

                    break 2;
                }

                if (preg_match('/^JWT\s+(.+)$/i', $authValue, $matches)) {
                    try {
                        $jwtSecretKey = GraphqlAuthentication::$plugin->getSettingsData($settings->jwtSecretKey);

                        $jwtConfig = Configuration::forSymmetricSigner(
                            new Sha256(),
                            InMemory::plainText($jwtSecretKey)
                        );

                        $validator = new SignedWith(
                            new Sha256(),
                            InMemory::plainText($jwtSecretKey)
                        );

                        $jwtConfig->setValidationConstraints($validator);
                        $constraints = $jwtConfig->validationConstraints();

                        $jwt = $jwtConfig->parser()->parse($matches[1]);

                        $event = new JwtValidateEvent([
                            'config' => $jwtConfig,
                        ]);

                        $this->trigger(self::EVENT_BEFORE_VALIDATE_JWT, $event);

                        try {
                            $jwtConfig->validator()->assert($jwt, ...$constraints, ...$event->config->validationConstraints());
                        } catch (RequiredConstraintsViolated $e) {
                            $errorService->throw(json_encode($e->violations()), 'FORBIDDEN');
                        }

                        $accessToken = $jwt->claims()->get('accessToken');
                        $token = Craft::$app->getGql()->getTokenByAccessToken($accessToken);
                    } catch (InvalidArgumentException $e) {
                        $errorService->throw($e, 'INVALID');
                    }

                    if (!$token) {
                        $errorService->throw($settings->invalidHeader, 'FORBIDDEN');
                    }

                    break 2;
                }
            }
        }

        if (!isset($token)) {
            $errorService->throw($settings->invalidHeader, 'FORBIDDEN');
        }

        $this->_validateExpiry($token);
        return $token;
    }

    public function rewriteJwtHeader()
    {
        if (!GraphqlAuthentication::$plugin->getInstance()->restriction->shouldRestrictRequests()) {
            return;
        }

        $request = Craft::$app->getRequest();
        $requestHeaders = $request->getHeaders();

        try {
            $token = $this->getHeaderToken();
            $requestHeaders->set('authorization', "Bearer {$token->accessToken}");
        } catch (Throwable $e) {}
    }

    public function getUserFromToken(): User
    {
        return Craft::$app->getUsers()->getUserById($this->_extractUserId());
    }

    public function create(User $user, Int $schemaId)
    {
        $this->_clearExpiredTokens();

        $settings = GraphqlAuthentication::$plugin->getSettings();
        $errorService = GraphqlAuthentication::$plugin->getInstance()->error;

        if (!$settings->jwtSecretKey) {
            $errorService->throw($settings->invalidJwtSecretKey, 'INVALID');
        }

        $accessToken = Craft::$app->getSecurity()->generateRandomString(32);
        $time = microtime(true);

        $token = new GqlToken([
            'name' => "user-{$user->id}-{$time}",
            'accessToken' => $accessToken,
            'enabled' => true,
            'schemaId' => $schemaId,
            'expiryDate' => (new DateTime())->modify("+ {$settings->jwtExpiration}"),
        ]);

        if (!Craft::$app->getGql()->saveToken($token)) {
            $errorService->throw(json_encode($token->getErrors()), 'FORBIDDEN');
        }

        $jwtSecretKey = GraphqlAuthentication::$plugin->getSettingsData($settings->jwtSecretKey);

        $jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($jwtSecretKey)
        );

        $now = new DateTimeImmutable();

        $builder = $jwtConfig->builder()
            ->issuedBy(Craft::$app->id ?? UrlHelper::cpUrl())
            ->issuedAt($now)
            ->expiresAt($now->modify($settings->jwtExpiration))
            ->relatedTo($user->id)
            ->withClaim('fullName', $user->fullName)
            ->withClaim('email', $user->email)
            ->withClaim('groups', array_column($user->getGroups(), 'name'))
            ->withClaim('schema', $token->getSchema()->name)
            ->withClaim('admin', $user->admin)
            ->withClaim('accessToken', $accessToken);

        $event = new JwtCreateEvent([
            'builder' => $builder,
            'user' => $user,
        ]);

        $this->trigger(self::EVENT_BEFORE_CREATE_JWT, $event);

        $jwt = $event->builder->getToken($jwtConfig->signer(), $jwtConfig->signingKey());
        $jwtExpiration = date_create(date('Y-m-d H:i:s'))->modify("+ {$settings->jwtExpiration}");
        $refreshToken = Craft::$app->getSecurity()->generateRandomString(32);
        $refreshTokenExpiration = date_create(date('Y-m-d H:i:s'))->modify("+ {$settings->jwtRefreshExpiration}");

        $refreshTokenElement = new RefreshToken([
            'token' => $refreshToken,
            'userId' => $user->id,
            'schemaId' => $schemaId,
            'expiryDate' => $refreshTokenExpiration->format('Y-m-d H:i:s'),
        ]);

        if (!Craft::$app->getElements()->saveElement($refreshTokenElement)) {
            $errorService->throw(json_encode($refreshTokenElement->getErrors()), 'INVALID');
        }

        $this->_setCookie('gql_refreshToken', $refreshToken, $settings->jwtRefreshExpiration);

        return [
            'jwt' => $jwt->toString(),
            'jwtExpiresAt' => $jwtExpiration->getTimestamp() * 1000,
            'refreshToken' => $refreshToken,
            'refreshTokenExpiresAt' => $refreshTokenExpiration->getTimestamp() * 1000,
        ];
    }

    // Protected Methods
    // =========================================================================

    protected function _setCookie(string $name, string $token, $expiration = null): bool
    {
        $settings = GraphqlAuthentication::$plugin->getSettings();
        $expiry = 0;

        if ($expiration) {
            $expiry = strtotime((new DateTime())->modify("+ {$expiration}")->format('Y-m-d H:i:s'));
        }

        if (PHP_VERSION_ID < 70300) {
            return setcookie($name, $token, $expiry, "/; samesite={$settings->sameSitePolicy}", '', true, true);
        }

        return setcookie($name, $token, [
            'expires' => $expiry,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => $settings->sameSitePolicy,
        ]);
    }

    protected function _extractUserId(): string
    {
        $token = $this->getHeaderToken();
        return explode('-', $token->name)[1];
    }

    protected function _validateExpiry(GqlToken $token)
    {
        if (!$token->expiryDate) {
            return;
        }

        if (strtotime(date('Y-m-d H:i:s')) < strtotime($token->expiryDate->format('Y-m-d H:i:s'))) {
            return;
        }

        GraphqlAuthentication::$plugin->getInstance()->error->throw(GraphqlAuthentication::$plugin->getSettings()->invalidHeader, 'FORBIDDEN');
    }

    protected function _clearExpiredTokens()
    {
        $gqlTokens = RecordsGqlToken::find()->where('expiryDate <= CURRENT_TIMESTAMP')->andWhere('name LIKE "%user-%"')->all();
        $gql = Craft::$app->getGql();

        foreach ($gqlTokens as $gqlToken) {
            $gql->deleteTokenById($gqlToken->id);
        }

        $refreshTokens = RefreshToken::find()->where('expiryDate <= CURRENT_TIMESTAMP')->all();
        $elements = Craft::$app->getElements();

        foreach ($refreshTokens as $refreshToken) {
            $elements->deleteElementById($refreshToken->id);
        }
    }
}
