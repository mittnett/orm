<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app;charset=utf8', 'root', 'secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
]);
$db = new \HbLib\DBAL\DatabaseConnection($pdo, new \HbLib\DBAL\Driver\MySQLDriver());

#[\HbLib\ORM\Attribute\Entity(table: 'companies')]
class Company implements \HbLib\ORM\Item {
    #[\HbLib\ORM\Attribute\Id, \HbLib\ORM\Attribute\Property(type: \HbLib\ORM\Attribute\Property::TYPE_INT)]
    private ?int $id;

    #[\HbLib\ORM\Attribute\Property]
    private string $name;

    /**
     * @var \HbLib\ORM\Item<Person>|null
     */
    #[\HbLib\ORM\Attribute\ManyToOne(targetEntity: Person::class, theirColumn: 'id', ourColumn: 'responsible_employee_id'),
        \HbLib\ORM\Attribute\Property(type: \HbLib\ORM\Attribute\Property::TYPE_INT, name: 'responsible_employee_id')]
    private ?\HbLib\ORM\Item $responsibleEmployee;

    #[\HbLib\ORM\Attribute\Property(type: \HbLib\ORM\Attribute\Property::TYPE_DATETIME, name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[\HbLib\ORM\Attribute\Property(type: \HbLib\ORM\Attribute\Property::TYPE_DATETIME, name: 'updated_at')]
    private ?DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->id = null;
        $this->name = $name;
        $this->responsibleEmployee = null;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = null;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        if ($this->id === null) {
            throw new \HbLib\ORM\UnpersistedException();
        }
        return $this->id;
    }

    public function get(): Company
    {
        return $this;
    }

    /**
     * @param \HbLib\ORM\Item<Person>|null $responsibleEmployee
     */
    public function setResponsibleEmployee(?\HbLib\ORM\Item $responsibleEmployee): void
    {
        $this->responsibleEmployee = $responsibleEmployee;
    }
}

#[\HbLib\ORM\Attribute\Entity(table: 'people')]
class Person implements \HbLib\ORM\IdentifiableEntityInterface {
    #[\HbLib\ORM\Attribute\Id, \HbLib\ORM\Attribute\Property(type: \HbLib\ORM\Attribute\Property::TYPE_INT)]
    private ?int $id;

    #[\HbLib\ORM\Attribute\Property]
    private string $firstname;

    #[\HbLib\ORM\Attribute\Property]
    private string $lastname;

    #[\HbLib\ORM\Attribute\Property]
    private string $email;

    #[\HbLib\ORM\Attribute\Property]
    private string $password;

    /**
     * @var \HbLib\ORM\Item<Company>|null
     */
    #[\HbLib\ORM\Attribute\ManyToOne(targetEntity: Company::class, theirColumn: 'id', ourColumn: 'company_id'),
        \HbLib\ORM\Attribute\Property(type: \HbLib\ORM\Attribute\Property::TYPE_INT, name: 'company_id')]
    private ?\HbLib\ORM\Item $company;

    #[\HbLib\ORM\Attribute\Property(type: \HbLib\ORM\Attribute\Property::TYPE_DATE)]
    private ?DateTimeImmutable $birthday;

    #[\HbLib\ORM\Attribute\Property(type: \HbLib\ORM\Attribute\Property::TYPE_DATETIME, name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[\HbLib\ORM\Attribute\Property(type: \HbLib\ORM\Attribute\Property::TYPE_DATETIME, name: 'updated_at')]
    private ?DateTimeImmutable $updatedAt;

    public function __construct(
        string $firstname,
        string $lastname,
        string $email,
        string $password,
        ?DateTimeImmutable $birthday,
    ) {
        $this->id = null;
        $this->firstname = $firstname;
        $this->lastname = $lastname;
        $this->email = $email;
        $this->password = $password;
        $this->birthday = $birthday;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = null;
    }

    public function getId(): int
    {
        return $this->id ?? throw new \HbLib\ORM\UnpersistedException();
    }

    /**
     * @return string
     */
    public function getFirstname(): string
    {
        return $this->firstname;
    }

    /**
     * @return string
     */
    public function getLastname(): string
    {
        return $this->lastname;
    }

    /**
     * @param \HbLib\ORM\Item<Company>|null $company
     */
    public function setCompany(?\HbLib\ORM\Item $company): void
    {
        $this->company = $company;
    }
}

$metadataFactory = new \HbLib\ORM\EntityMetadataFactory();
$dispatcher = new class() implements \Psr\EventDispatcher\EventDispatcherInterface {
    public function dispatch(object $event)
    {
        // no-op
    }
};

$persister = new \HbLib\ORM\EntityPersister($db, $metadataFactory, $dispatcher);
$hydrator = new \HbLib\ORM\EntityHydrator($db, $metadataFactory);

echo 'Mem: ' . human_filesize(memory_get_usage(true)) . PHP_EOL;

$refs = [
    $metadataFactory->getMetadata(Company::class),
    $metadataFactory->getMetadata(Person::class),
];

$faker = \Faker\Factory::create();

function human_filesize($size, $precision = 2) {
    static $units = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
    $step = 1024;
    $i = 0;
    while (($size / $step) > 0.9) {
        $size = $size / $step;
        $i++;
    }
    return round($size, $precision).$units[$i];
}

$times = [];

$run = static function () use ($db, $persister, $hydrator): void {
    $companyStmt = $db->query('SELECT * FROM companies LIMIT 200');

    /** @var Company[] $companies */
    $companies = $hydrator->fromStatementArray(
        className: Company::class,
        statement: $companyStmt,
        reuse: false,
    );

    //$persister->capture($companies);

    $personStmt = $db->prepare('SELECT * FROM people WHERE company_id = :company_id LIMIT 1');

    foreach ($companies as $company) {
        $personStmt->bindValue(':company_id', $company->getId(), \PDO::PARAM_INT);
        $personStmt->execute();
        /** @var Person[] $people */
        $people = $hydrator->fromStatementArray(className: Person::class, statement: $personStmt, reuse: true);
        $company->setResponsibleEmployee(new \HbLib\ORM\ObjectItem($people[array_key_first($people)]));
    }

    $persister->flush($companies);
};

for ($i = 0; $i < 400; $i++) {
    $start = microtime(true);
    $run();

    $times[] = microtime(true) - $start;
    //echo round($times[array_key_last($times)], 4) . ' sec' . PHP_EOL;
}

sort($times);

$calcAverage = static fn (array $sums): float => array_sum($sums) / count($sums);
$calcMedian = static function (array $sums): float {
    $sumCount = count($sums);
    $middleValueKey = floor(($sumCount - 1) / 2);

    if ($sumCount % 2) {
        return $sums[$middleValueKey];
    }

    $low = $sums[$middleValueKey];
    $high = $sums[$middleValueKey + 1];

    return ($low + $high) / 2;
};

echo 'Total: ' . round(array_sum($times), 4) . ' sec' . PHP_EOL;
echo 'Average: ' . round($calcAverage($times), 4) . ' sec' . PHP_EOL;
echo 'Median: ' . round($calcMedian($times), 4) . ' sec' . PHP_EOL;
