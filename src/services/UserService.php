<?php

namespace jamesedmonston\graphqlauthentication\services;

use Craft;
use craft\base\Component;
use craft\base\VolumeInterface;
use craft\elements\User;
use craft\gql\arguments\elements\User as UserArguments;
use craft\gql\interfaces\elements\User as ElementsUser;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use craft\records\User as UserRecord;
use craft\services\Gql;
use craft\web\View;
use GraphQL\Type\Definition\Type;
use jamesedmonston\graphqlauthentication\gql\Auth;
use jamesedmonston\graphqlauthentication\GraphqlAuthentication;
use yii\base\Event;

use craft\errors\InvalidSubpathException;
use craft\errors\VolumeException;

class UserService extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            [$this, 'registerGqlQueries']
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_MUTATIONS,
            [$this, 'registerGqlMutations']
        );
    }

    public function registerGqlQueries(Event $event)
    {
        $settings = GraphqlAuthentication::$plugin->getSettings();
        $errorService = GraphqlAuthentication::$plugin->getInstance()->error;

        $event->queries['viewer'] = [
            'description' => 'Gets authenticated user.',
            'type' => ElementsUser::getType(),
            'args' => [],
            'resolve' => function () use ($settings, $errorService) {
                $user = GraphqlAuthentication::$plugin->getInstance()->token->getUserFromToken();

                if (!$user) {
                    $errorService->throw($settings->userNotFound, 'INVALID');
                }

                return $user;
            },
        ];
    }

    public function registerGqlMutations(Event $event)
    {
        $elements = Craft::$app->getElements();
        $users = Craft::$app->getUsers();
        $permissions = Craft::$app->getUserPermissions();
        $gql = Craft::$app->getGql();
        $settings = GraphqlAuthentication::$plugin->getSettings();
        $tokenService = GraphqlAuthentication::$plugin->getInstance()->token;
        $errorService = GraphqlAuthentication::$plugin->getInstance()->error;
        $fieldsService = Craft::$app->getFields();

        $event->mutations['authenticate'] = [
            'description' => 'Logs a user in. Returns user and token.',
            'type' => Type::nonNull(Auth::getType()),
            'args' => [
                'email' => Type::nonNull(Type::string()),
                'password' => Type::nonNull(Type::string()),
            ],
            'resolve' => function ($source, array $arguments) use ($users, $settings, $tokenService, $errorService) {
                $email = $arguments['email'];
                $password = $arguments['password'];

                $user = $users->getUserByUsernameOrEmail($email);

                if (!$user) {
                    $errorService->throw($settings->invalidLogin, 'INVALID');
                }

                $permissions = Craft::$app->getUserPermissions();
                $userPermissions = $permissions->getPermissionsByUserId($user->id);

                if (!in_array('accessCp', $userPermissions)) {
                    $permissions->saveUserPermissions($user->id, array_merge($userPermissions, ['accessCp']));
                }

                if (!$user->authenticate($password)) {
                    $permissions->saveUserPermissions($user->id, $userPermissions);
                    $errorService->throw($settings->invalidLogin, 'INVALID');
                }

                $permissions->saveUserPermissions($user->id, $userPermissions);

                $schemaId = $settings->schemaId ?? null;

                if ($settings->permissionType === 'multiple') {
                    $userGroup = $user->getGroups()[0] ?? null;

                    if ($userGroup) {
                        $schemaId = $settings->granularSchemas["group-{$userGroup->id}"]['schemaId'] ?? null;
                    }
                }

                if (!$schemaId) {
                    $errorService->throw($settings->invalidSchema, 'INVALID');
                }

                $this->_updateLastLogin($user);
                $token = $tokenService->create($user, $schemaId);
                return $this->getResponseFields($user, $schemaId, $token);
            },
        ];

        if ($settings->permissionType === 'single' && $settings->allowRegistration) {
            $event->mutations['register'] = [
                'description' => 'Registers a user. Returns user and token.',
                'type' => Type::nonNull(Auth::getType()),
                'args' => array_merge(
                    [
                        'email' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string()),
                        'firstName' => Type::string(),
                        'lastName' => Type::string(),
                        'username' => Type::string()
                    ],
                    UserArguments::getContentArguments()
                ),
                'resolve' => function ($source, array $arguments) use ($settings, $tokenService, $errorService) {
                    $schemaId = $settings->schemaId;

                    if (!$schemaId) {
                        $errorService->throw($settings->invalidSchema, 'INVALID');
                    }

                    $user = $this->create($arguments, $settings->userGroup);
                    $token = $tokenService->create($user, $schemaId);

                    return $this->getResponseFields($user, $schemaId, $token);
                },
            ];
        }

        if ($settings->permissionType === 'multiple') {
            $userGroups = Craft::$app->getUserGroups()->getAllGroups();

            foreach ($userGroups as $userGroup) {
                if (!($settings->granularSchemas["group-{$userGroup->id}"]['allowRegistration'] ?? false)) {
                    continue;
                }

                $handle = ucfirst($userGroup->handle);

                $event->mutations["register{$handle}"] = [
                    'description' => "Registers a {$userGroup->name} user. Returns user and token.",
                    'type' => Type::nonNull(Auth::getType()),
                    'args' => array_merge(
                        [
                            'email' => Type::nonNull(Type::string()),
                            'password' => Type::nonNull(Type::string()),
                            'firstName' => Type::string(),
                            'lastName' => Type::string(),
                            'username' => Type::string()
                        ],
                        UserArguments::getContentArguments()
                    ),
                    'resolve' => function ($source, array $arguments) use ($settings, $tokenService, $errorService, $userGroup) {
                        $schemaId = $settings->granularSchemas["group-{$userGroup->id}"]['schemaId'] ?? null;

                        if (!$schemaId) {
                            $errorService->throw($settings->invalidSchema, 'INVALID');
                        }

                        $user = $this->create($arguments, $userGroup->id);
                        $token = $tokenService->create($user, $schemaId);

                        return $this->getResponseFields($user, $schemaId, $token);
                    },
                ];
            }
        }

        $event->mutations['forgottenPassword'] = [
            'description' => "Sends a password reset email to the user's email address. Returns success message.",
            'type' => Type::nonNull(Type::string()),
            'args' => [
                'email' => Type::nonNull(Type::string()),
            ],
            'resolve' => function ($source, array $arguments) use ($users, $settings) {
                $email = $arguments['email'];
                $user = $users->getUserByUsernameOrEmail($email);
                $message = $settings->passwordResetSent;

                if (!$user) {
                    return $message;
                }

                $users->sendPasswordResetEmail($user);
                return $message;
            },
        ];

        $event->mutations['setPassword'] = [
            'description' => 'Sets password for unauthenticated user. Requires `code` and `id` from Craft reset password email. Returns success message.',
            'type' => Type::nonNull(Type::string()),
            'args' => [
                'password' => Type::nonNull(Type::string()),
                'code' => Type::nonNull(Type::string()),
                'id' => Type::nonNull(Type::string()),
            ],
            'resolve' => function ($source, array $arguments) use ($elements, $users, $settings, $errorService) {
                $password = $arguments['password'];
                $code = $arguments['code'];
                $id = $arguments['id'];

                $user = $users->getUserByUid($id);

                if (!$user || !$users->isVerificationCodeValidForUser($user, $code)) {
                    $errorService->throw($settings->invalidRequest, 'INVALID');
                }

                $user->newPassword = $password;

                if (!$elements->saveElement($user)) {
                    $errorService->throw(json_encode($user->getErrors()), 'INVALID');
                }

                $users->activateUser($user);
                return $settings->passwordSaved;
            },
        ];

        $event->mutations['updatePassword'] = [
            'description' => 'Updates password for authenticated user. Requires access token and current password. Returns success message.',
            'type' => Type::nonNull(Type::string()),
            'args' => [
                'currentPassword' => Type::nonNull(Type::string()),
                'newPassword' => Type::nonNull(Type::string()),
                'confirmPassword' => Type::nonNull(Type::string()),
            ],
            'resolve' => function ($source, array $arguments) use ($elements, $users, $permissions, $settings, $tokenService, $errorService) {
                $user = $tokenService->getUserFromToken();

                if (!$user) {
                    $errorService->throw($settings->invalidPasswordUpdate, 'INVALID');
                }

                $newPassword = $arguments['newPassword'];
                $confirmPassword = $arguments['confirmPassword'];

                if ($newPassword !== $confirmPassword) {
                    $errorService->throw($settings->invalidPasswordMatch, 'INVALID');
                }

                $currentPassword = $arguments['currentPassword'];
                $userPermissions = $permissions->getPermissionsByUserId($user->id);

                if (!in_array('accessCp', $userPermissions)) {
                    $permissions->saveUserPermissions($user->id, array_merge($userPermissions, ['accessCp']));
                }

                $user = $users->getUserByUsernameOrEmail($user->email);

                if (!$user->authenticate($currentPassword)) {
                    $permissions->saveUserPermissions($user->id, $userPermissions);
                    $errorService->throw($settings->invalidPasswordUpdate, 'INVALID');
                }

                $permissions->saveUserPermissions($user->id, $userPermissions);

                $user->newPassword = $newPassword;

                if (!$elements->saveElement($user)) {
                    $errorService->throw(json_encode($user->getErrors()), 'INVALID');
                }

                return $settings->passwordUpdated;
            },
        ];

        $event->mutations['updateViewer'] = [
            'description' => 'Updates authenticated user. Returns user.',
            'type' => ElementsUser::getType(),
            'args' => array_merge(
                [
                    'email' => Type::string(),
                    'firstName' => Type::string(),
                    'lastName' => Type::string(),
                    'username' => Type::string(),
                ],
                UserArguments::getContentArguments()
            ),
            'resolve' => function ($source, array $arguments) use ($elements, $settings, $tokenService, $errorService, $fieldsService) {
                $user = $tokenService->getUserFromToken();

                if (!$user) {
                    $errorService->throw($settings->invalidUserUpdate, 'INVALID');
                }

                if (isset($arguments['email'])) {
                    if ($user->username == $user->email && !isset($arguments['username'])) {
                        $user->username = $arguments['email'];
                    }

                    $user->email = $arguments['email'];
                }

                if (isset($arguments['username'])) {
                    $user->username = $arguments['username'];
                }

                if (isset($arguments['firstName'])) {
                    $user->firstName = $arguments['firstName'];
                }

                if (isset($arguments['lastName'])) {
                    $user->lastName = $arguments['lastName'];
                }

                $customFields = UserArguments::getContentArguments();

                foreach ($customFields as &$key) {
                    if (is_array($key) && isset($key['name'])) {
                        $key = $key['name'];
                    }

                    if (!isset($arguments[$key]) || !count($arguments[$key])) {
                        continue;
                    }

                    $field = $fieldsService->getFieldByHandle($key);
                    $type = get_class($field);
                    $value = $arguments[$key];

                    if (!StringHelper::containsAny($type, ['Entries', 'Categories', 'Assets'])) {
                        $value = $value[0];
                    }

                    $user->setFieldValue($key, $value);
                }

                if (!$elements->saveElement($user)) {
                    $errorService->throw(json_encode($user->getErrors()), 'INVALID');
                }

                return $user;
            },
        ];

        $event->mutations['deleteCurrentToken'] = [
            'description' => 'Deletes authenticated user access token. Useful for logging out of current device. Returns boolean.',
            'type' => Type::nonNull(Type::boolean()),
            'args' => [],
            'resolve' => function () use ($gql, $settings, $tokenService, $errorService) {
                $token = $tokenService->getHeaderToken();

                if (!$token) {
                    $errorService->throw($settings->tokenNotFound, 'INVALID');
                }

                $gql->deleteTokenById($token->id);

                return true;
            },
        ];

        $event->mutations['deleteAllTokens'] = [
            'description' => 'Deletes all access tokens belonging to the authenticated user. Useful for logging out of all devices. Returns boolean.',
            'type' => Type::nonNull(Type::boolean()),
            'args' => [],
            'resolve' => function () use ($gql, $settings, $tokenService, $errorService) {
                $user = $tokenService->getUserFromToken();

                if (!$user) {
                    $errorService->throw($settings->tokenNotFound, 'INVALID');
                }

                $savedTokens = $gql->getTokens();

                if (!$savedTokens || !count($savedTokens)) {
                    $errorService->throw($settings->tokenNotFound, 'INVALID');
                }

                foreach ($savedTokens as $savedToken) {
                    if (StringHelper::contains($savedToken->name, "user-{$user->id}")) {
                        $gql->deleteTokenById($savedToken->id);
                    }
                }

                return true;
            },
        ];
    }

    public function create(array $arguments, int $userGroup): User
    {
        $email = $arguments['email'];
        $password = $arguments['password'];
        $firstName = isset($arguments['firstName']) ? $arguments['firstName'] : '';
        $lastName = isset($arguments['lastName']) ? $arguments['lastName'] : '';
        $username = isset($arguments['username']) ? $arguments['username'] : $email;

        $user = new User();
        $user->username = $username;
        $user->email = $email;
        $user->firstName = $firstName;
        $user->lastName = $lastName;

        if ($password) {
            $user->newPassword = $password;
        }

        $customFields = UserArguments::getContentArguments();
        $fieldsService = Craft::$app->getFields();

        foreach ($customFields as $key) {
            if (is_array($key) && isset($key['name'])) {
                $key = $key['name'];
            }

            if (!isset($arguments[$key]) || !count($arguments[$key])) {
                continue;
            }

            $field = $fieldsService->getFieldByHandle($key);
            $type = get_class($field);
            $value = $arguments[$key];

            if (!StringHelper::containsAny($type, ['Entries', 'Categories', 'Assets'])) {
                $value = $value[0];
            }

            $user->setFieldValue($key, $value);
        }

        $requiresVerification = Craft::$app->getProjectConfig()->get('users.requireEmailVerification');

        if ($requiresVerification) {
            $user->pending = true;
        }

        $elements = Craft::$app->getElements();

        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_SITE);

        if (!$elements->saveElement($user)) {
            GraphqlAuthentication::$plugin->getInstance()->error->throw(json_encode($user->getErrors()), 'INVALID');
        }

        $users = Craft::$app->getUsers();

        if ($userGroup) {
            $users->assignUserToGroups($user->id, [$userGroup]);
        }

        if ($requiresVerification) {
            $users->sendActivationEmail($user);
        }

        $this->_updateLastLogin($user);
        return $user;
    }

    public function getResponseFields(User $user, int $schemaId, $token): array
    {
        $newAssetFolderId = 0;

        if ($user->id) {
            $volume = Craft::$app->getVolumes()->getVolumeByHandle('lopAssets');
            $newAssetFolderId = $this->_userPhotoFolderId($user, $volume);
        }

        return [
            'user' => $user,
            'userFolderId' => $newAssetFolderId,
            'schema' => Craft::$app->getGql()->getSchemaById($schemaId)->name,
            'jwt' => $token['jwt'],
            'jwtExpiresAt' => $token['jwtExpiresAt'],
            'refreshToken' => $token['refreshToken'],
            'refreshTokenExpiresAt' => $token['refreshTokenExpiresAt'],
        ];
    }

    // Protected Methods
    // =========================================================================

    protected function _updateLastLogin(User $user)
    {
        $now = DateTimeHelper::currentUTCDateTime();
        $userRecord = UserRecord::findOne($user->id);
        $userRecord->lastLoginDate = $now;
        $userRecord->save();
    }

    /**
     * PRIVATE FUNCTION TAKEN FROM USERS MODEL FROM CRAFT CMS CORE.
     * We needed to reproduce the creation of a folder at the registration
     * of a new user. The folder takes the user ID as a name.
     *
     * Returns the folder that a user’s photo should be stored.
     *
     * @param User $user
     * @param VolumeInterface $volume The user photo volume
     * @return int
     * @throws VolumeException if the user photo volume doesn’t exist
     * @throws InvalidSubpathException if the user photo subpath can’t be resolved
     */
    private function _userPhotoFolderId(User $user, VolumeInterface $volume): int
    {
        $subpath = (string)Craft::$app->getProjectConfig()->get('users.photoSubpath');

        if (
            $subpath !== ''
        ) {
            try {
                $subpath = Craft::$app->getView()->renderObjectTemplate($subpath, $user);
            } catch (\Throwable $e) {
                throw new InvalidSubpathException($subpath);
            }
        }

        return Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($subpath, $volume);
    }
}
