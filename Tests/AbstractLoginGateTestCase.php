<?php

namespace Anyx\LoginGateBundle\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class AbstractLoginGateTestCase extends KernelTestCase
{
    /**
     * @param \Symfony\Component\HttpKernel\KernelInterface $kernel
     */
    abstract protected function loadFixtures(KernelInterface $kernel);

    /**
     * @var \Symfony\Component\HttpKernel\Client
     */
    protected $client;

    /**
     * @var \Doctrine\Common\DataFixtures\ReferenceRepository
     */
    protected static $referenceRepository;

    public function setUp()
    {
        static::bootKernel();
        $this->loadFixtures(static::$kernel);

        $this->client = $this->getContainer()->get('test.client');
        $this->client->followRedirects();
    }

    public function testCorrectLogin()
    {
        $peter = $this->getReference('user.peter');

        $response = $this->attemptLogin($peter->getEmail(), 'test');

        $this->assertTrue($response->isSuccessful());
        $crawler = new Crawler($response->getContent());
        $this->assertGreaterThan(0, $crawler->filter('html:contains("' . $peter->getEmail() . '")')->count());
    }

    public function testCatchBruteForceAttempt()
    {
        $peter = $this->getReference('user.peter');

        for ($i = 0; $i < 3; $i++) {
            $response = $this->attemptLogin($peter->getEmail(), 'wrong password');
            $crawler = new Crawler($response->getContent());
            $this->assertGreaterThan(0, $crawler->filter('html:contains("Bad credentials")')->count());
        }

        $crawler = $this->client->request('GET', '/login');
        $this->assertGreaterThan(0, $crawler->filter('html:contains("You can not log on now")')->count());

        //test event
        $this->client->request(
            'POST',
            '/login',
            [
                '_username' => 'baduser',
                '_password' => 'we'
            ]
        );

        $crawler = new Crawler($this->client->getResponse()->getContent());
        $this->assertGreaterThan(0, $crawler->filter('html:contains("BRUTE FORCE ATTEMPT")')->count());
    }

    public function testClearLoginAttempts()
    {
        $this->client->request('GET', '');
        /** @var \Anyx\LoginGateBundle\Service\BruteForceChecker $bruteForceChecker */
        $bruteForceChecker = static::$container->get('anyx.login_gate.brute_force_checker');
        $request = $this->client->getRequest();

        $this->assertEquals(0, $bruteForceChecker->getStorage()->getCountAttempts($request));

        $peter = $this->getReference('user.peter');
        $this->attemptLogin($peter->getEmail(), 'wrong password');
        $this->assertEquals(1, $bruteForceChecker->getStorage()->getCountAttempts($request));

        $this->attemptLogin($peter->getEmail(), 'test');
        $this->assertEquals(0, $bruteForceChecker->getStorage()->getCountAttempts($request));
    }

    /**
     * @param string $username
     * @param string $password
     * @return null|\Symfony\Component\HttpFoundation\Response
     */
    protected function attemptLogin($username, $password)
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('login')->form();

        $form['_username'] = $username;
        $form['_password'] = $password;

        $this->client->submit($form);

        return $this->client->getResponse();
    }

    /**
     * @param string $name
     * @return mixed
     */
    protected function getReference($name)
    {
        return $this->getReferenceRepository()->getReference($name);
    }

    /**
     * @return \Symfony\Component\DependencyInjection\Container
     */
    protected function getContainer()
    {
        return static::$container;
    }

    /**
     * @return \Doctrine\Common\DataFixtures\ReferenceRepository
     */
    protected function getReferenceRepository()
    {
        return self::$referenceRepository;
    }
}
