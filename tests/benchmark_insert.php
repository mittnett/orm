<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app;charset=utf8', 'root', 'secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
]);
$db = new \HbLib\DBAL\DatabaseConnection($pdo, new \HbLib\DBAL\Driver\MySQLDriver());
$db->query(<<<EOL
DROP TABLE IF EXISTS companies;
CREATE TABLE companies (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    responsible_employee_id INT(11) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL
) ENGINE=InnoDb
EOL);

$db->query(<<<EOL
DROP TABLE IF EXISTS people;
CREATE TABLE people (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    firstname VARCHAR(255) NOT NULL,
    lastname VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    birthday DATE DEFAULT NULL,
    company_id INT(11) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL
) ENGINE=InnoDb;
EOL);

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
class Person {
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

$peopleQuery = $db->query('SELECT * FROM companies');
$iterator = $hydrator->fromStatement(className:Company::class, statement: $peopleQuery, reuse: false);

foreach ($iterator as $person) {
    echo $person->getId() . PHP_EOL;
}

echo 'Mem: ' . human_filesize(memory_get_usage(true)) . PHP_EOL;

$refs = [
    $metadataFactory->getMetadata(Company::class),
    $metadataFactory->getMetadata(Person::class),
];

$start = microtime(true);
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

$companies = [];
for ($i = 1; $i < 1_000; $i++) {
    $company = new Company($faker->company);
    $companies[] = $company;
}

echo "Flush companies before, " . human_filesize(memory_get_usage(true)) . PHP_EOL;
$persister->flush($companies);
echo "Flush companies after, " . human_filesize(memory_get_usage(true)) . PHP_EOL;

foreach (array_chunk($companies, 100) as $companyChunk) {
    $people = [];

    foreach ($companyChunk as $company) {
        $max = random_int(1, 20);
        for ($i2 = 0; $i2 < $max; $i2++) {
            $person = new Person(
                firstname: $faker->firstName,
                lastname: $faker->lastName,
                email: $faker->email,
                password: $faker->password,
                birthday: DateTimeImmutable::createFromMutable($faker->dateTime),
            );
            $person->setCompany($company);

            $people[] = $person;
        }
    }

    $persister->flush($people);

    echo "Flushed people, " . human_filesize(memory_get_usage(true)) . PHP_EOL;
}

echo 'Done ' . round(microtime(true) - $start, 2) . ' sec' . PHP_EOL;

$companyStmt = $db->query('SELECT * FROM companies');
$companies = $hydrator->fromStatementArray(
    className: Company::class,
    statement: $companyStmt,
    reuse: true,
);

$persister->capture($companies);

foreach ($companies as $company) {
    $company->setActivated();
}
