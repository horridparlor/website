<?php

namespace system;

const ACCESS_RIGHT_IS_SUPER_ADMIN = 'isSuperAdmin';
const ACCESS_RIGHT_CAN_RELEASE = 'canRelease';
const ACCESS_RIGHT_CAN_MANAGE_ADMINS = 'canManageAdmins';
const ACCESS_RIGHT_CAN_MANAGE_USERS = 'canManageUsers';
const ACCESS_RIGHT_CAN_MANAGE_CARDS = 'canManageCards';
const ACCESS_RIGHT_CAN_MANAGE_IMAGE_GENERATION = 'canManageImageGeneration';
const ACCESS_RIGHT_CAN_CLEAR_CONTENT = 'canClearContent';
const ACCESS_RIGHT_HAS_UNLIMITED_TOKENS = 'hasUnlimitedTokens';
const ACCESS_RIGHT_CAN_SHARE_TOKENS = 'canShareTokens';
const ACCESS_RIGHT_CAN_MESSAGE_ADMINS = 'canMessageAdmins';
const ACCESS_RIGHT_CAN_MASS_EXPORT = 'canMassExport';
const ACCESS_RIGHT_CAN_CREATE_CONTENT = 'canCreateContent';
const ACCESS_RIGHT_CAN_GENERATE_IMAGES = 'canGenerateImages';
const ACCESS_RIGHT_CAN_MESSAGE = 'canMessage';
const ACCESS_RIGHT_AUTO_REFILL_TOKENS = 'autoRefillTokens';
const ACCESS_RIGHT_IS_REGULAR_USER = 'isRegularUser';
const ACCESS_RIGHT_IS_PRIORITY_USER = 'isPriorityUser';
const ACCESS_RIGHT_IS_EMPLOYEE = 'isEmployee';
const ACCESS_RIGHT_IS_CONTENT_CREATOR = 'isContentCreator';
const IS_MISSING_ANY_ACCESS_RIGHT = 'None of the accepted access rights %s';
const IS_MISSING_ACCESS_RIGHT = 'Access right {%s} missing';
const ALTERING_ADMIN_RIGHT = 'You cannot %s admin access right {%s}';
const ALTERING_REMOVE = 'remove';
const ALTERING_ADD = 'add';
const NO_ERROR = 'No error, { User->getError() called. }';

const ADMIN_RIGHTS = array(
    ACCESS_RIGHT_IS_SUPER_ADMIN,
    ACCESS_RIGHT_CAN_RELEASE,
    ACCESS_RIGHT_CAN_MANAGE_ADMINS,
    ACCESS_RIGHT_CAN_MANAGE_USERS,
    ACCESS_RIGHT_CAN_MANAGE_CARDS,
    ACCESS_RIGHT_CAN_MANAGE_IMAGE_GENERATION,
    ACCESS_RIGHT_CAN_CLEAR_CONTENT,
    ACCESS_RIGHT_HAS_UNLIMITED_TOKENS,
    ACCESS_RIGHT_CAN_SHARE_TOKENS,
    ACCESS_RIGHT_CAN_MESSAGE_ADMINS,
    ACCESS_RIGHT_CAN_MASS_EXPORT
);

class User
{
    private int $id;
    private string $username;
    private string $displayName;
    private \stdClass $accessRights;
    private string $accessRightErrorString;
    private string $adminAccessRight;
    private bool $isActive;

    public function __construct(int $id, string $username, string $displayName, \stdClass $accessRights, bool $isActive)
    {
        $this->id = $id;
        $this->username = $username;
        $this->displayName = $displayName;
        $this->accessRights = $accessRights;
        $this->isActive = $isActive;
        $this->accessRightErrorString = NO_ERROR;
    }

    public static function newDummyUser(\stdClass $accessRights): User {
        return new User(0, '', '', $accessRights, true);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function isSuperAdmin(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_IS_SUPER_ADMIN);
    }
    private function hasAllRights(): bool
    {
        return $this->hasAccessRight(ACCESS_RIGHT_IS_SUPER_ADMIN);
    }
    public function canRelease(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_RELEASE);
    }
    public function canManageAdmins(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_MANAGE_ADMINS);
    }
    public function canManageUsers(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_MANAGE_USERS);
    }
    public function canManageCards(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_MANAGE_CARDS);
    }
    public function canManageImageGeneration(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_MANAGE_IMAGE_GENERATION);
    }
    public function canClearContent(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_CLEAR_CONTENT);
    }
    public function hasUnlimitedTokens(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_HAS_UNLIMITED_TOKENS);
    }
    public function canShareTokens(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_SHARE_TOKENS);
    }
    public function canMessageAdmins(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_MESSAGE_ADMINS);
    }
    public function canMassExport(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_MASS_EXPORT);
    }
    public function canCreateContent(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_CREATE_CONTENT);
    }
    public function canGenerateImages(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_GENERATE_IMAGES);
    }
    public function canMessage(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_CAN_MESSAGE);
    }
    public function autoRefillTokens(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_AUTO_REFILL_TOKENS);
    }
    public function isRegularUser(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_IS_REGULAR_USER);
    }
    public function isPriorityUser(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_IS_PRIORITY_USER);
    }
    public function isEmployee(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_IS_EMPLOYEE);
    }
    public function isContentCreator(): bool
    {
        return $this->checkAccess(ACCESS_RIGHT_IS_CONTENT_CREATOR);
    }

    public function canManageUsersImageGeneration(int $userId): bool
    {
        return $this->hasAccessToUser($userId) || $this->checkAccess(ACCESS_RIGHT_CAN_MANAGE_IMAGE_GENERATION);
    }
    public function hasAccessToUser(int $userId): bool
    {
        return $this->id == $userId || $this->canManageUsers();
    }

    private function checkAccess(string $key): bool
    {
        $hasAccess = $this->hasAllRights() || $this->hasAccessRight($key);
        if (!$hasAccess) {
            $this->accessRightErrorString = sprintf(IS_MISSING_ACCESS_RIGHT, $key);
        }
        return $hasAccess;
    }

    private function checkAccessAny(array $keys): bool
    {
        if ($this->hasAllRights()) {
            return true;
        }
        foreach ($keys as $key) {
            if ($this->hasAccessRight($key)) {
                return true;
            }
        }
        $keysString = '{' . implode('}/{', $keys) . '}';
        $this->accessRightErrorString = sprintf(IS_MISSING_ANY_ACCESS_RIGHT, $keysString);
        return false;
    }

    private function hasAccessRight(string $key): bool
    {
        if (!property_exists($this->accessRights, $key)) {
            return false;
        }
        return $this->accessRights->$key;
    }

    public function hasAdminRights(): bool
    {
        foreach (ADMIN_RIGHTS as $adminRight) {
            if ($this->hasAccessRight($adminRight)) {
                $this->adminAccessRight = $adminRight;
                return true;
            }
        }
        return false;
    }

    public function getError(): array
    {
        return array(
            'error' => $this->accessRightErrorString
        );
    }

    public function getAdminAccessRight(): string
    {
        return $this->adminAccessRight;
    }

    private function extractAdminRights(\stdClass $accessRights): array {
        $adminRights = array();
        foreach ($accessRights as $key => $value) {
           if (in_array($key, ADMIN_RIGHTS) && !!$value) {
               $adminRights[] = $key;
           }
        }
        sort($adminRights);
        return $adminRights;
    }

    public function wouldChangeAdminRights(\stdClass $accessRights): bool
    {
        $currentAdminRights = $this->extractAdminRights($this->accessRights);
        $newAdminRights = $this->extractAdminRights($accessRights);
        $removedRights = array_diff($currentAdminRights, $newAdminRights);
        $addedRights = array_diff($newAdminRights, $currentAdminRights);
        if ($removedRights) {
            $this->accessRightErrorString = sprintf(ALTERING_ADMIN_RIGHT, ALTERING_REMOVE, reset($removedRights));
            return true;
        }
        if ($addedRights) {
            echo json_encode($addedRights);
            $this->accessRightErrorString = sprintf(ALTERING_ADMIN_RIGHT, ALTERING_ADD, reset($addedRights));
            return true;
        }
        return false;
    }

    public function getReplacements(string $idKey = 'ownerId', array $additionalids = array()): array {
        $replacements = array(
            $idKey => ['value' => $this->id, 'type' => \PDO::PARAM_INT],
        );
        foreach ($additionalids as $key => $value) {
            $replacements[$key] = ['value' => $value, 'type' => \PDO::PARAM_INT];
        }
        return $replacements;
    }

    public function getDisplayName(): string {
        return $this->displayName;
    }
}