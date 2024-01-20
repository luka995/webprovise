<?php

// Interface for API service
interface ApiService
{
    public function fetchData($url): array;
}

// Implementation of a service using cURL to fetch data from the API
class CurlApiService implements ApiService
{
    public function fetchData($url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}


class DIContainer
{
    private array $instances = [];

    public function get($class)
    {
        if (!isset($this->instances[$class])) {
            $this->instances[$class] = new $class();
        }

        return $this->instances[$class];
    }
}

readonly class Travel
{
    public function __construct(
        private string $id,
        private float $price,
        private string $departure,
        private string $destination,
        private string $companyId,
        private string $createdAt
    ) {}

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }
}

class Company
{
    public function __construct(
        public string $id,
        public string $parentId,
        public string $name,
        public string $createdAt,
    ) {}

    public float $costs;
    public array $children = [];
    private array $travels;


    public function getChildren(): array
    {
        return $this->children;
    }

    public function getTravels(): array
    {
        return $this->travels;
    }

    public function setChildren(Company $child): void
    {
        $this->children[] = $child;
    }

    public function setTravel(Travel $travel): void
    {
        $this->travels[] = $travel;
    }

    public function calculateCosts(): float
    {
        $totalCosts = 0;

        foreach ($this->getTravels() as $travel) {
            $totalCosts += $travel->getPrice();
        }
        foreach ($this->getChildren() as $children) {
            $totalCosts += $children->calculateCosts();
        }

        return $this->costs = $totalCosts;

    }
}

readonly class TravelService
{
    private array $travels;

    public function __construct(private ApiService $apiService) {
        $this->travels = $this->apiService->fetchData('https://5f27781bf5d27e001612e057.mockapi.io/webprovise/travels');
    }

    public function getTravels(): array
    {
        return $this->travels;
    }
}

readonly class CompanyService
{
    private array $companies;

    public function __construct(private ApiService $apiService) {
        $this->companies = $this->apiService->fetchData('https://5f27781bf5d27e001612e057.mockapi.io/webprovise/companies');    }

    public function getCompanies(): array
    {
        return $this->companies;
    }
}



class CompanyTreeService
{
    private array $map;

    private Company $root;

    public function __construct(private readonly CompanyService $companyService, private readonly TravelService $travelService) {
        $this->buildCompanyMap();
        $this->addTravelsToCompanies();
        $this->appendChildren();
        $this->root->calculateCosts();
    }

    private function buildCompanyMap(): void
    {
        $companiesData = $this->companyService->getCompanies();

        foreach ($companiesData as $company) {
            $compObj = new Company(
                id: $company['id'],
                parentId: $company['parentId'],
                name: $company['name'],
                createdAt: $company['createdAt']
            );
            $this->map[$compObj->id] = $compObj;
        }
    }

    public function getMap(): false|string
    {
        return json_encode($this->root, JSON_PRETTY_PRINT);
    }

    private function addRoot(Company $company) : void
    {
        //if company parentId does not exist in map key - this is root company
        if (!array_key_exists($company->parentId, $this->map)) {
            $this->root = $company;
        }
    }

    private function appendChildren(): void
    {
        foreach ($this->map as $company) {
            $this->addChildToParentCompany($company);
            $this->addRoot($company);
        }
    }

    private function addChildToParentCompany(Company $compObj) : void
    {
        if (isset($compObj->parentId)) {
            $parentCompany = $this->getCompanyFromMap($compObj->parentId);
            $parentCompany?->setChildren($compObj);
        }
    }

    /**
     * @throws JsonException
     */
    private function getCompanyFromMap(string $id) : ?Company
    {
        if(!array_key_exists($id, $this->map)) {
            if ($id !== "0") {
                throw new JsonException('Company with given ID does not exist in map.');
            }
            //it is root
            return null;
        }
        return $this->map[$id];
    }

    private function addTravelsToCompanies() : void
    {
        $travelsData = $this->travelService->getTravels();
        foreach ($travelsData as $travel) {
            $travelObj = new Travel(
                id: $travel['id'],
                price: $travel['price'],
                departure: $travel['departure'],
                destination: $travel['destination'],
                companyId: $travel['companyId'],
                createdAt: $travel['createdAt']
            );
            $cmp = $this->map[$travelObj->getCompanyId()];
            $cmp?->setTravel($travelObj);
        }
    }

}

readonly class TestScript
{
    public function __construct(
        private CompanyTreeService $companyTreeService
    ) {}

    public function execute(): void
    {
        $start = microtime(true);

        echo $this->companyTreeService->getMap();

        echo 'Total time: ' . (microtime(true) - $start);
    }
}

// Creating a DI container
$diContainer = new DIContainer();

// Creating instances and injecting dependencies into the DI container
$apiService = $diContainer->get(CurlApiService::class);
$travelService = new TravelService($apiService);
$companyService = new CompanyService($apiService);
try {
    $companyTreeService = new CompanyTreeService($companyService, $travelService);
} catch (JsonException $exception) {
    echo $exception->getMessage() . "\n";
}

// Creating an instance of the test script and executing it
(new TestScript($companyTreeService))->execute();