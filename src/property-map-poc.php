<?php

class Property implements JsonSerializable
{
    const TYPE_LITERAL = 'literal';
    const TYPE_OBJECT = 'object';
    const TYPE_ARRAY = 'array';

    protected $name;
    protected $type;
    protected $meta = [];

    public function __construct(
        string $name,
        string $type,
        array $meta = []
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->meta = $meta;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return bool
     */
    public function hasName(): bool
    {
        return !empty($this->name);
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return bool
     */
    public function matchesType(string $type): bool
    {
        return ($this->type === $type);
    }

    /**
     * @return array
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @return array|mixed
     */
    public function jsonSerialize()
    {
        return get_object_vars($this);
    }
}

/**
 * @param array $map
 * @param null $sourceData
 * @throws Exception
 */
function mapSourceToDestination(
    array $map,
    $sourceData = null
) {
    $sourceValueIndex = [];
    $results = [];

    foreach ($map as $index => $paths) {
        $result = [];
        $result['src'] = $paths['src'];
        $result['dst'] = $paths['dst'];

        $result['srcProps'] = buildProperties($paths['src']);
        $result['dstProps'] = buildProperties($paths['dst']);

        if (array_key_exists($paths['src'], $sourceValueIndex)) {
            // don not process the same values more that once
            $result['srcValue'] = $results[$sourceValueIndex[$paths['src']]]['srcValue'];
            $results[$index] = $result;
            continue;
        }

        $result['srcValue'] = findSourcePropertyValue($sourceData, $result['srcProps']);
        $sourceValueCache[$paths['src']] = $index;
        $results[$index] = $result;
    }

    return $results;
}

/**
 * @param string $propertyPath
 * @return array
 */
function buildProperties(
    string $propertyPath
) {
    $values = preg_split('"(?<!\\\\)[.,|&]"', $propertyPath);

    $properties = [];

    foreach ($values as $key => $value) {
        $nextKey = $key + 1;
        $isNextObjectProperty = isset($values[$nextKey]);

        $parts = preg_split('"(?<!\\\\)(\[\])"', $value);

        $type = ($isNextObjectProperty ? Property::TYPE_OBJECT : Property::TYPE_LITERAL);

        $partsCount = count($parts);

        if ($key === 0 && !empty($parts[0])) {
            // add root object def
            $properties[] = new Property(
                '',
                Property::TYPE_OBJECT,
                ['root' => true]
            );
        }

        if ($partsCount === 1) {
            $properties[] = new Property(
                $value,
                $type
            );
            continue;
        }

        $countDownDepth = $partsCount - 1;
        foreach ($parts as $subKey => $subValue) {
            if ($countDownDepth === 0) {
                break;
            }
            $countDownDepth--;
            $properties[] = new Property(
                $subValue,
                Property::TYPE_ARRAY
            );
        }
    }

    return $properties;
}

/**
 * @param $sourceData
 * @param Property[] $sourceProperties
 * @param int $key
 * @return array
 * @throws Exception
 */
function findSourcePropertyValue(
    $sourceData,
    array $sourceProperties,
    int $key = 0
) {
    if (!isset($sourceProperties[$key])) {
        echo '>>> case End';

        return $sourceData;
    }

    $nextKey = $key + 1;

    /** @var Property $property */
    $property = $sourceProperties[$key];
    $propertyName = $property->getName();
    $propertyHasName = $property->hasName();

    echo "\n>>>> finding: " . $key
        . ' type: ' . $property->getType()
        . ' name: "' . $property->getName() . '"'
        . ' in data: ' . json_encode($sourceData) . "\n";

    if ($property->matchesType(Property::TYPE_LITERAL) && !$propertyHasName) {
        echo '>>> case literal';

        return $sourceData;
    }

    if ($property->matchesType(Property::TYPE_LITERAL) && $propertyHasName) {
        echo '>>> case literal with name ';
        assertIsPropertyExists($sourceData, $propertyName);

        return $sourceData->{$propertyName};
    }

    if ($property->matchesType(Property::TYPE_OBJECT) && ($key === 0)) {
        echo '>>> case object root';

        return findSourcePropertyValue(
            $sourceData,
            $sourceProperties,
            $nextKey
        );
    }

    if ($property->matchesType(Property::TYPE_OBJECT)) {
        echo '>>> case object with name';
        assertIsPropertyExists($sourceData, $propertyName);

        return findSourcePropertyValue(
            $sourceData->{$propertyName},
            $sourceProperties,
            $nextKey
        );
    }

    if ($property->matchesType(Property::TYPE_ARRAY)) {
        echo '>>> case array';
        $values = [];
        assertIsArray($sourceData);
        foreach ($sourceData as $sourceDataKey => $sourceDatum) {
            $values[$sourceDataKey] = findSourcePropertyValue(
                $sourceDatum,
                $sourceProperties,
                $nextKey
            );
        }

        return $values;
    }

    // not found situation
    echo '>>> case not found';

    return null;
}

/**
 * @param $data
 * @throws Exception
 */
function assertIsObject($data)
{
    if (!is_object($data)) {
        throw new \Exception(
            'Data expected to be object, got: ' . gettype($data)
            . ' with value: ' . json_encode($data)
        );
    }
}

/**
 * @param $data
 * @throws Exception
 */
function assertIsArray($data)
{
    if (!is_array($data)) {
        throw new \Exception(
            'Data expected to be array, got: ' . gettype($data)
            . ' with value: ' . json_encode($data)
        );
    }
}

/**
 * @param $data
 * @throws Exception
 */
function assertIsPropertyExists($data, $property)
{
    assertIsObject($data);

    if (!property_exists($data, $property)) {
        throw new \Exception(
            'Data expected to be object with property: "' . $property . '"'
            . ' got: ' . json_encode($data)
        );
    }
}

function test()
{
    $examples = [
        '',
        '[][][]',
        'user',
        'user.id',
        'user.address.postalCode',
        'user.roles[].name\.test[][].name\[\]][',
        'user.pees[][][]',
        'user.poos[].test[]',
        'ddddd[][][][].dd',
    ];
    foreach ($examples as $example) {
        echo("\n>>> example: " . $example);
        $sourceProperties = buildProperties($example);

        echo(
            "\n" . json_encode($sourceProperties, JSON_PRETTY_PRINT) . "\n"
        );
    }
}

$test1 = [
    'source' => [
        (object)[
            'id' => 'id1',
            'name' => 'name1',
            'notused' => 'nope1',
            'address' => (object)[
                'postalCode' => '1postal2',
            ],
            'roles' => [
                (object)['name' => 'admin', 'description' => 'adminn stuff']
            ]
        ],
        (object)[
            'id' => 'id2',
            'name' => 'name2',
            'notused' => 'nope2',
            'address' => (object)[
                'postalCode' => '2postal3',
            ],
            'roles' => [
                (object)['name' => 'admin2', 'description' => 'adminn stuff2']
            ]
        ],
    ],

    'map' => [
        [
            'src' => '[].id',
            'dst' => 'users[].userId',
        ],
        [
            'src' => '[].name',
            'dst' => 'users[].userUserName',
        ],
        [
            'src' => '[].address.postalCode',
            'dst' => 'users[].postalCode',
        ],
    ],

    'destinationExpected' => (object)[
        'users' => [
            (object)[
                'userId' => '',
                'userName' => ''
            ]
        ]
    ],
];

$test2 = [
    'source' => (object)[
        'id' => 'id1',
        'name' => 'name1',
        'notused' => 'nope1',
        'address' => (object)[
            'postalCode' => '1postal2',
        ],
        'roles' => [
            (object)['name' => 'admin', 'description' => 'adminn stuff']
        ]
    ],

    'map' => [
        [
            'src' => 'id',
            'dst' => 'users[].userId',
        ],
        [
            'src' => 'name',
            'dst' => 'users[].userUserName',
        ],
        [
            'src' => 'address.postalCode',
            'dst' => 'users[].postalCode',
        ],
    ],

    'destinationExpected' => (object)[
        'users' => [
            (object)[
                'userId' => '',
                'userName' => ''
            ]
        ]
    ],
];

$test3 = [
    'source' => 'literalUserId',

    'map' => [
        [
            'src' => '',
            'dst' => 'users[].userId',
        ],
        [
            'src' => '',
            'dst' => 'users[].userUserName',
        ],
    ],

    'destinationExpected' => (object)[
        'users' => [
            (object)[
                'userId' => '',
                'userName' => ''
            ]
        ]
    ],
];

$map = $test2['map'];
$sourceData = $test2['source'];

$results = mapSourceToDestination($map, $sourceData);

echo("\n>>>>> SourceData: " . json_encode($sourceData, JSON_PRETTY_PRINT) . "\n");
echo("\n>>>>> Results: " . json_encode($results, JSON_PRETTY_PRINT) . "\n");
