<?php

namespace Anyx\LoginGateBundle\Tests;

use Doctrine\Common\DataFixtures\ReferenceRepository;
use Staffim\RestClient\Client as RestClient;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class AbstractLoginGateTestCase extends KernelTestCase
{
    abstract protected function loadFixtures(KernelInterface $kernel);

    /**
     * @var \Doctrine\Common\DataFixtures\ReferenceRepository
     */
    protected static $referenceRepository;

    public function setUp(): void
    {
        static::bootKernel();
        $this->loadFixtures(static::$kernel);
    }

    public function testCorrectJsonLogin()
    {
        $peter = $this->getReference('user.peter');

        $response = $this->attemptJsonLogin($peter->getEmail(), 'password');

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testCorrectFormLogin()
    {
        $peter = $this->getReference('user.peter');

        $response = $this->attemptFormLogin($peter->getEmail(), 'password');
        $this->assertEquals(200, $response->getStatusCode());

        $this->assertTrue($response->isSuccessful());
    }

    public function testCatchBruteForceAttemptInJson()
    {
        $peter = $this->getReference('user.peter');

        for ($i = 0; $i < 3; ++$i) {
            $response = $this->attemptJsonLogin($peter->getEmail(), 'wrong password', 401);
            $this->assertEquals('Invalid credentials.', $response->getData()['error']);
        }

        $response = $this->attemptJsonLogin($peter->getEmail(), 'wrong password', 403);
        $this->assertEquals('Too many login attempts', $response->getData()['error']);
    }

    public function testCatchBruteForceAttemptInForm()
    {
        $peter = $this->getReference('user.peter');

        for ($i = 0; $i < 2; ++$i) {
            $response = $this->attemptFormLogin($peter->getEmail(), 'wrong password', 401);
            $this->assertStringContainsString('Invalid credentials.', $response->getContent());
        }

        $response = $this->attemptFormLogin($peter->getEmail(), 'wrong password', 401);
        $this->assertEquals('Too many login attempts', $response->getContent());
    }

    public function testClearLoginAttempts()
    {
        $httpClient = $this->createRestClient('');
        $httpClient->get('web');

        /** @var \Anyx\LoginGateBundle\Service\BruteForceChecker $bruteForceChecker */
        $bruteForceChecker = static::getContainer()->get('anyx.login_gate.brute_force_checker');
        $request = $httpClient->getKernelBrowser()->getRequest();

        $peter = $this->getReference('user.peter');
        $this->assertEquals(0, $bruteForceChecker->getStorage()->getCountAttempts($request, $peter->getUsername()));

        $this->attemptJsonLogin($peter->getEmail(), 'wrong password', 401);
        $this->assertEquals(1, $bruteForceChecker->getStorage()->getCountAttempts($request, $peter->getUsername()));

        $this->attemptJsonLogin($peter->getEmail(), 'password');
        $this->assertEquals(0, $bruteForceChecker->getStorage()->getCountAttempts($request, $peter->getUsername()));
    }

    public function testCheckUsernamesFromSameIpAddress()
    {
        $peter = $this->getReference('user.peter');
        $helen = $this->getReference('user.helen');

        $httpClient = $this->createRestClient('');
        $httpClient->get('web');

        $this->attemptJsonLogin($peter->getEmail(), 'wrong password', 401);
        $this->attemptJsonLogin($peter->getEmail(), 'wrong password', 401);
        $this->attemptJsonLogin($peter->getEmail(), 'wrong password', 401);

        $responseData = $this->attemptJsonLogin($peter->getEmail(), 'wrong password', 403)->getData();
        $this->assertEquals('Too many login attempts', $responseData['error']);

        $this->assertEquals(200, $this->attemptJsonLogin($helen->getEmail(), 'password', 200)->getStatusCode());

        $responseData = $this->attemptJsonLogin($peter->getEmail(), 'wrong password', 403)->getData();
        $this->assertEquals('Too many login attempts', $responseData['error']);
    }

    /**
     * @return \Staffim\RestClient\Response
     */
    protected function attemptJsonLogin(string $username, string $password, int $status = 200)
    {
        $httpClient = $this->createRestClient('/api/login');

        $loginData = ['username' => $username, 'password' => $password];

        return $httpClient->post($loginData, $status);
    }

    protected function attemptFormLogin(string $username, string $password): Response
    {
        /** @var $client KernelBrowser */
        $client = $this->getContainer()->get('test.client');
        $client->followRedirects();

        $crawler = $client->request('GET', '/web/login');
        $form = $crawler->selectButton('Sign in')->form();

        $form['email'] = $username;
        $form['password'] = $password;

        $client->submit($form);

        return $client->getResponse();
    }

    protected function getReference(string $name)
    {
        return $this->getReferenceRepository()->getReference($name);
    }

    protected function getReferenceRepository(): ReferenceRepository
    {
        return self::$referenceRepository;
    }

    protected static function executeCommand(string $command, array $options = [])
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput(
            array_merge(
                [
                    'command' => $command,
                ],
                $options
            )
        );

        $output = new BufferedOutput();
        $application->run($input, $output);

        return $output->fetch();
    }

    protected function createRestClient(string $url, array $headers = [])
    {
        return new RestClient(
            self::$kernel,
            $url,
            array_merge(['CONTENT_TYPE' => 'application/json'], $headers)
        );
    }
}
