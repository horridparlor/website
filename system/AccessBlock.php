<?php

namespace system;

use PDO;

enum StandardType: string {
    case BOOLEAN = 'boolean';
    case DATE = '^2[0-9][0-9][0-9]-(0[1-9]|1[0-2])-(0[1-9]|1[0-9]|2[0-9]|3[0-1])$';
    case DATETIME = '^2[0-9][0-9][0-9]-(0[1-9]|1[0-2])-(0[1-9]|1[0-9]|2[0-9]|3[0-1])T([0-1][0-9]|[2][0-3]):[0-5][0-9]$';
    case ID = 'id';
    case NUMBER = 'number';
    case RULE = '^(\$[1-9]\d*|\(|\)|\s+|AND|OR|NOT|TRUE|FALSE)+$';
    case STRING = '^(?=.*\S).+$';
    case UNKNOWN = '';
}
const REGEX_TYPES = array(
    StandardType::DATE,
    StandardType::DATETIME,
);
Class StandardMessages {
    const ERROR_INVALID_REQUEST = "There is a problem with the request schema";
    const ERROR_NO_ACCESS = "User has no access to this function";
    const NO_ACCESS_TO_USER = "User has no access to the given user";
    const NULL = 101;
    const HAVE_TO = "have to";
    const CANNOT = "cannot";
    const TYPE = "type";
    const REGEX = "regex";
    const NOT = "not";
    const EMPTY = "empty";
    const STRING = "string";
    const REQUIRED_PARAM_MISSING = "Required parameter %s is missing";
    const FORBIDDEN_PARAM = "Forbidden param %s";
    const PARAM_MISSING_REGEX = "/^Required parameter .* is missing$/";
    const REQUIRED_PARAM_WITH_OPTION_MISSING = "Requires parameter (%s or %s)%s";
    const REQUIRED_PARAM_ARRAY = "Parameter %s %s be an array";
    const REQUIRED_ARRAY_SHORT = "Parameter %s needs minimum %s item%s, %s given";
    const REQUIRED_ARRAY_LONG = "Parameter %s fits maximum %s item%s, %s given";
    const REQUIRED_PARAM_INVALID_TYPE = "Parameter %s should be %s %s, {%s} is %s";
    const REQUIRED_PARAM_NUMBER_NOT_IN_RANGE = "Parameter %s should be %s, is {%s}";
    const REQUIRED_PARAM_NOT_UNIQUE = "Parameter %s must be unique, {%s} already exists";
    const REQUIRED_PARAM_NOT_EXIST = "Parameter %s%s, %s with id {%s} does not exist";
    const PARAM_CASCADING_ENTITY = "With %s {%s}, related %s exists%s";
    const REQUEST_DATA_PARAM_MISSING_FROM_SQL_QUERY = "SqlQuery {%s}, missing param requestData";

    public static function doesNotExist(string $itemName): string {
        return "Selected " . $itemName . " does not exist";
    }

    public static function alreadyInUse(string $itemName, ?string $itemTarget = null,
                                        ?string $attribute = null, ?string $operation = 'change'): string {
        return ucfirst($itemName)." is already in use".($itemTarget ? " e.g. it has values in ".$itemTarget : '').
            ($attribute ? ", cannot ".$operation." ".$attribute." anymore" : '');
    }

    public static function cannotMake(string $itemName, string $attribute, ?string $targetName): string {
        return self::alreadyInUse($itemName, $targetName, $attribute, 'make');
    }

    public static function paramMissing(array $paramTree): string {
        return sprintf(self::REQUIRED_PARAM_MISSING, implode("->", $paramTree));
    }

    public static function forbiddenParam(array $paramTree): string {
        return sprintf(self::FORBIDDEN_PARAM, implode("->", $paramTree));
    }

    public static function paramWithOptionMissing(string $param, string $option, array $paramTree): string {
        $tree = implode("->", $paramTree);
        return sprintf(self::REQUIRED_PARAM_WITH_OPTION_MISSING, $param, $option, $tree ? ' ' . $tree : '');
    }

    public static function paramNumberNotInRange(int|float $value, int|null $min, int|null $max, array $paramTree): string {
        $rangeString = self::getRangeString($min, $max);
        return sprintf(self::REQUIRED_PARAM_NUMBER_NOT_IN_RANGE, implode("->", $paramTree), $rangeString, $value);
    }

    private static function getRangeString(int|null $min, int|null $max): string {
        if (is_null($min)) {
            return "maximum " . $max;
        } elseif (is_null($max)) {
            return "minimum " . $min;
        }
        return sprintf("between %s and %s", $min, $max);
    }

    public static function paramInvalidType(mixed $value, StandardType $type, string $redux, array $paramTree): string {
        $typeOrRegex = self::typeOrRegex($value, $type);
        return sprintf(self::REQUIRED_PARAM_INVALID_TYPE, implode("->", $paramTree), $typeOrRegex,
            ($typeOrRegex == self::REGEX or !in_array($type, REGEX_TYPES)) ? $redux : StandardType::STRING->value,
            (is_array($value) || is_object($value)) ? json_encode($value) : $value,
            $typeOrRegex == self::REGEX ? self::NOT : ($value === '' ? self::EMPTY : gettype($value)));
    }

    public static function paramNotUnique(mixed $value, array $paramTree): string {
        $tree = implode("->", $paramTree);
        return sprintf(self::REQUIRED_PARAM_NOT_UNIQUE, $tree, $value);
    }
    public static function paramNotExist(array $prefixSuffix, array $paramTree): string {
        $tree = implode("->", $paramTree);
        $prefix = $prefixSuffix['prefix'] ? ' {' . $prefixSuffix['prefix'] . '}' : '';
        return sprintf(self::REQUIRED_PARAM_NOT_EXIST, $tree, $prefix, $prefixSuffix['entity'], $prefixSuffix['suffix']);
    }
    public static function paramCascadingEntity(mixed $cascadingValue, mixed $value, string $suffix, array $paramTree): string {
        $tree = implode("->", $paramTree);
        return sprintf(self::PARAM_CASCADING_ENTITY, $tree, $value, $cascadingValue, $suffix);
    }
    private static function typeOrRegex(mixed $value, StandardType $type): string {
        return (!in_array($type, REGEX_TYPES) or gettype($value) != 'string') ? self::TYPE : self::REGEX;
    }

    public static function paramBeArray(array $paramTree, bool $toBe = true): string {
        return sprintf(self::REQUIRED_PARAM_ARRAY, implode("->", $paramTree), self::haveToOrCannot($toBe));
    }

    public static function haveToOrCannot(bool $value): string {
        return $value ? self::HAVE_TO : self::CANNOT;
    }
    public static function paramArrayShort(int $size, int $required, array $paramTree): string {
        return sprintf(self::REQUIRED_ARRAY_SHORT, implode("->", $paramTree), $required, self::sIfMany($required), $size);
    }
    public static function paramArrayLong(int $size, int $required, array $paramTree): string {
        return sprintf(self::REQUIRED_ARRAY_LONG, implode("->", $paramTree), $required, self::sIfMany($required), $size);
    }

    private static function sIfMany(int $value): string {
        return $value > 1 ? 's' : '';
    }

    public static function requestDataParamMissingFromSqlQuery(string $queryIdentifier) {
        return sprintf(self::REQUEST_DATA_PARAM_MISSING_FROM_SQL_QUERY, $queryIdentifier);
    }
}
const NO_SIZE = -1;
class ParamCheck {
    public string $param;
    public array $children;
    public ParamCheck $option;
    public array $also;
    public array $iterative;
    public bool $isIterative;
    public bool $required;
    public bool $forbidden;
    public int|null $min;
    public int|null $max;
    public int $minSize;
    public int $maxSize;
    public StandardType $type;
    public int $pdoType;
    public string $redux;
    public SqlComparison|null $unique;
    public SqlComparison|null $exists;
    public SqlComparison|null $cascade;
    public bool $debug;

    public function __construct() {
        $this->children = array();
        $this->also = array();
        $this->iterative = array();
        $this->isIterative = false;
        $this->required = true;
        $this->forbidden = false;
        $this->min = null;
        $this->max = null;
        $this->minSize = NO_SIZE;
        $this->maxSize = NO_SIZE;
        $this->type = StandardType::UNKNOWN;
        $this->pdoType = \PDO::PARAM_INT;
        $this->redux = '';
        $this->unique = null;
        $this->exists = null;
        $this->cascade = null;
        $this->debug = false;
    }
}
class SqlComparison {
    private string $sql;
    private array $replacements;
    public function __construct(string $sql, array $replacements = [], \stdClass $requestData = null) {
        $this->sql = $sql;
        $replacements = $this->fillReplacements($replacements, $requestData);
        $this->replacements = $replacements;
    }
    public function getSql(): string {
        return $this->sql;
    }
    public function getReplacements(): array {
        return $this->replacements;
    }
    public function eatReplacement(string $key, mixed $value, int $pdoType): void {
        $this->replacements[$key] = ['value' => $value, 'type' => $pdoType];
    }
    public function fillReplacements(array $source, \stdClass|null $requestData): array {
        $replacements = array();
        foreach ($source as $key => $replacement) {
            $value = $replacement['value'];
            $replacements[$key] = $replacement;
            if (is_string($value) && strpos($value, '$') === 0) {
                $replace = substr($value, 1);
                if (is_null($requestData)) {
                    echo StandardMessages::requestDataParamMissingFromSqlQuery($key) . ' ';
                }
                if (isset($requestData->$replace)) {
                    $replacements[$key]['value'] = $requestData->$replace;
                }
            }
        }
        return $replacements;
    }
}
class AccessBlock
{
    public static function findMissingParam(array $requiredParams, Database $database): string|null {
        foreach ($requiredParams as $param) {
            $missing = self::handleParamCheck(self::buildParamCheck($param), $database->getRequestData(), $database);
            if ($missing) { return $missing; }
        }
        return null;
    }

    private static function handleParamCheck(ParamCheck $paramCheck, \stdClass $request, Database $database, ?array $paramTree = []): string|null {
        $param = $paramCheck->param;
        $type = $paramCheck->type;
        $value = null;
        $treeWithThis = [...$paramTree, $param];
        if (!property_exists($request, $param) or is_null($request->$param)) {
            if (isset($paramCheck->option)) {
                $option = $paramCheck->option;
                $missing = self::handleParamCheck($option, $request, $database, $paramTree);
                if ($missing) {
                    return $paramCheck->required ?
                        preg_match(StandardMessages::PARAM_MISSING_REGEX, $missing)
                            ? StandardMessages::paramWithOptionMissing($param, $option->param, $paramTree)
                            : $missing
                        : null;
                }
            } else {
                return $paramCheck->required ? StandardMessages::paramMissing($treeWithThis) : null;
            }
        } else {
            if ($paramCheck->forbidden) {
                return StandardMessages::forbiddenParam($treeWithThis);
            }
            $value = $request->$param;
            if (isset($paramCheck->option)) {
                $option = $paramCheck->option;
                $option->required = false;
                $missing = self::handleParamCheck($option, $request, $database, $paramTree);
                if ($missing) {
                    return $missing;
                }
            }
        }
        if (!is_null($value)) {
            if (!$paramCheck->isIterative && $type != StandardType::UNKNOWN and !self::checkParamType($value, $type, $paramCheck)) {
                if ($type == StandardType::NUMBER && ($paramCheck->min != null || $paramCheck->max != null) && (is_int($value) || is_float($value))) {
                    return StandardMessages::paramNumberNotInRange($value, $paramCheck->min, $paramCheck->max, $paramTree);
                }
                return StandardMessages::paramInvalidType($value, $type, $paramCheck->redux, $treeWithThis);
            }
            if (!self::checkParamUnique($value, $paramCheck, $database)) {
                return StandardMessages::paramNotUnique($value, $treeWithThis);
            }
            if (!is_null($paramCheck->exists)) {
                $exists = self::checkParamExists($value, $paramCheck, $database);
                if (!is_null($exists)) {
                    return StandardMessages::paramNotExist($exists, $treeWithThis);
                }
            }
            if (!is_null($paramCheck->cascade)) {
                $cascadingEntity = self::checkParamCascade($value, $paramCheck, $database);
                if (!is_null($cascadingEntity)) {
                    return StandardMessages::paramCascadingEntity($cascadingEntity['prefix'], $value, $cascadingEntity['suffix'], $treeWithThis);
                }
            }
        }
        foreach ($paramCheck->also as $node) {
            $missing = self::handleParamCheck($node, $request, $database, $paramTree);
            if ($missing) { return $missing; }
        }
        foreach ($paramCheck->children as $child) {
            if (is_array($value)) {
                $value = new \stdClass();
            }
            $missing = self::handleParamCheck($child, $value, $database, $treeWithThis);
            if ($missing) { return $missing; }
        }
        $index = 0;
        if (empty($paramCheck->iterative) and !$paramCheck->isIterative) {
            if (is_array($value)) {
                return StandardMessages::paramBeArray($treeWithThis, false);
            }
            return null;
        }
        if (!is_array($value)) {
            return StandardMessages::paramBeArray($treeWithThis);
        }
        if ($paramCheck->isIterative) {
            return self::handleArrayParamCheck($value, $paramCheck, $paramTree);
        }
        $missing = self::handleArrayParamCheck($value, $paramCheck, $paramTree);
        if ($missing) { return $missing; }
        foreach ($value as $iteration) {
            foreach ($paramCheck->iterative as $child) {
                $missing = self::handleParamCheck($child, $iteration, $database, [...$paramTree, $param . sprintf('[%d]', $index)]);
                if ($missing) { return $missing; }
            }
            $index += 1;
        }
        return null;
    }

    private static function checkParamType(mixed $value, StandardType $type, ParamCheck $paramCheck): bool {
        return match ($type) {
            StandardType::BOOLEAN => is_bool($value),
            StandardType::ID => is_int($value) and $value > 0,
            StandardType::NUMBER => (is_int($value) or is_float($value)) and self::isNumberInAllowedRange($value, $paramCheck),
            default => is_string($value) && preg_match('/' . $paramCheck->redux . '/s', $value),
        };
    }

    private static function isNumberInAllowedRange(int|float $value, ParamCheck $paramCheck): bool {
        return (is_null($paramCheck->min) || $value >= $paramCheck->min) &&
            (is_null($paramCheck->max) || $value <= $paramCheck->max);
    }

    private static function checkParamUnique(mixed $value, ParamCheck $paramCheck, Database $database): bool {
        $comparison = $paramCheck->unique;
        if (!$comparison) {
            return true;
        }
        $comparison->eatReplacement('comparedValue', $value, $paramCheck->pdoType);
        return empty($database->query($comparison->getSql(), $comparison->getReplacements(), $paramCheck->debug));
    }

    private static function checkParamExists(mixed $value, ParamCheck $paramCheck, Database $database): null|array
    {
        $comparison = $paramCheck->exists;
        $exists = array(
            'prefix' => '',
            'suffix' => '',
            'entity' => ''
        );
        $comparison->eatReplacement('comparedValue', $value, $paramCheck->pdoType);
        $result = $database->query($comparison->getSql(), $comparison->getReplacements(), $paramCheck->debug);
        if (empty($result)) {
            return null;
        }
        $result = $result[0];
        $missingId = $result['missingId'] ?? null;
        $exists['entity'] = $result['entityType'] ?? 'entity';
        if ($missingId) {
            $exists['prefix'] = $value;
            $exists['suffix'] = $missingId;
        } else {
            $exists['suffix'] = $value;
        }
        return $exists;
    }
    private static function checkParamCascade(mixed $value, ParamCheck $paramCheck, Database $database): null|array {
        $comparison = $paramCheck->cascade;
        $cascadingEntity = array(
            'prefix' => '',
            'suffix' => ''
        );
        $comparison->eatReplacement('comparedValue', $value, $paramCheck->pdoType);
        $result = $database->query($comparison->getSql(), $comparison->getReplacements(), $paramCheck->debug);
        if (!sizeof($result)) {
            return null;
        }
        $result = $result[0];
        $entityType = $result['entityType'] ?? 'entity';
        $cascadingId = $result['cascadingId'] ?? null;
        $cascadingName = $result['cascadingName'] ?? null;
        $proceedingComment = $result['proceedingComment'] ?? null;
        $cascadingEntity['prefix'] = implode(
                ' ',
                array_merge(
                    [$entityType],
                    $cascadingId ? ['{' . $cascadingId . ($cascadingName ? ':' : '')] : [],
                    $cascadingId && $cascadingName ? [$cascadingName] : []
                )
            ) . ($cascadingId ? '}' : '');
        $cascadingEntity['suffix'] = $proceedingComment ? ', ' . $proceedingComment : '';
        return $cascadingEntity;
    }

    private static function handleArrayParamCheck(array $source, ParamCheck $paramCheck, array $paramTree): string | null {
        $tree = [...$paramTree, $paramCheck->param];
        $size = sizeof($source);
        if ($paramCheck->minSize != NO_SIZE and $size < $paramCheck->minSize) {
            return StandardMessages::paramArrayShort($size, $paramCheck->minSize, $tree);
        } elseif ($paramCheck->maxSize != NO_SIZE and $size > $paramCheck->maxSize) {
            return StandardMessages::paramArrayLong($size, $paramCheck->maxSize, $tree);
        }
        return null;
    }

    private static function buildParamCheck($array): ParamCheck {
        $paramCheck = new ParamCheck();
        if (isset($array['param'])) {
            $paramCheck->param = $array['param'];
        }
        if (isset($array['children']) && is_array($array['children'])) {
            foreach ($array['children'] as $child) {
                $paramCheck->children[] = self::buildParamCheck($child);
            }
        }
        if (isset($array['option']) && is_array($array['option'])) {
            $paramCheck->option = self::buildParamCheck($array['option']);
        }
        if (isset($array['also']) && is_array($array['also'])) {
            foreach ($array['also'] as $key => $val) {
                $paramCheck->also[$key] = self::buildParamCheck($val);
            }
        }
        if (isset($array['iterative']) && is_array($array['iterative'])) {
            foreach ($array['iterative'] as $iter) {
                $paramCheck->iterative[] = self::buildParamCheck($iter);
            }
        }
        if (isset($array['isIterative']) && $array['isIterative']) {
            $paramCheck->isIterative = true;
        }
        if (isset($array['required']) && !$array['required']) {
            $paramCheck->required = false;
        }
        if (isset($array['forbidden']) && $array['forbidden']) {
            $paramCheck->forbidden = true;
            $paramCheck->required = false;
        }
        if (isset($array['notEmpty']) && $array['notEmpty']) {
            $paramCheck->minSize = 1;
        }
        if (isset($array['min'])) {
            $paramCheck->min = $array['min'];
        }
        if (isset($array['max'])) {
            $paramCheck->max = $array['max'];
        }
        if (isset($array['minSize'])) {
            $paramCheck->minSize = $array['minSize'];
        }
        if (isset($array['maxSize'])) {
            $paramCheck->maxSize = $array['maxSize'];
        }
        if (isset($array['type'])) {
            $type = $array['type'];
            if (is_string($type)) {
                $paramCheck->type = StandardType::STRING;
                $paramCheck->redux = $type;
            } else {
                $paramCheck->type = $type;
                $paramCheck->redux = $type->value;
            }
            if ($paramCheck->type == StandardType::STRING) {
                $paramCheck->pdoType = PDO::PARAM_STR;
            }
        }
        if (isset($array['unique'])) {
            $paramCheck->unique = $array['unique'];
        }
        if (isset($array['exists'])) {
            $paramCheck->exists = $array['exists'];
        }
        if (isset($array['cascade'])) {
            $paramCheck->cascade = $array['cascade'];
        }
        if (isset($array['debug'])) {
            $paramCheck->debug = $array['debug'];
        }
        return $paramCheck;
    }
}