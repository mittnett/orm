<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app;charset=utf8', 'root', 'secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
]);
$db = new \HbLib\DBAL\DatabaseConnection($pdo);
$db->query(<<<EOL
CREATE TABLE IF NOT EXISTS companies (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    responsible_employee_id INT(11) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL
) ENGINE=InnoDb
EOL);

$db->query(<<<EOL
CREATE TABLE IF NOT EXISTS people (
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
class Company {
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
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
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

exit;

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

for ($i = 1; $i < 30_000; $i++) {
    $people = [];

    $company = new Company($faker->company);
    $persister->flush([$company]);

    for ($i2 = 1; $i2 < 10_000; $i2++) {
        $person = new Person(
            firstname: $faker->firstName,
            lastname: $faker->lastName,
            email: $faker->email,
            password: $faker->password,
            birthday: DateTimeImmutable::createFromMutable($faker->dateTime),
        );
        $person->setCompany(new \HbLib\ORM\ObjectItem($company));

        $people[] = $person;
    }

    $persister->flush($people);

    echo "Num: $i, " . human_filesize(memory_get_usage(true)) . PHP_EOL;
}
