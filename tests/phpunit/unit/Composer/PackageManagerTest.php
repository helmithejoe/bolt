<?php

namespace Bolt\Tests\Composer;

use Bolt\Composer\JsonManager;
use Bolt\Composer\PackageManager;
use Bolt\Extension\Manager;
use Bolt\Logger\FlashLogger;
use Composer\Package\CompletePackage;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Bolt\Composer\PackageManager
 *
 * @author Ross Riley <riley.ross@gmail.com>
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class PackageManagerTest extends TestCase
{
    public function testSetup()
    {
        $app = new Application();
        $jsonManager = $this->getMockBuilder(JsonManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['update'])
            ->getMock()
        ;
        $jsonManager
            ->expects($this->once())
            ->method('update')
        ;
        $app['extend.manager.json'] = $jsonManager;
        $app['extend.writeable'] = true;
        $app['extend.site'] = 'https://example.com';
        $app['request_stack']->push(Request::createFromGlobals());

        $mock = new MockHandler([
            new Psr7\Response(200),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $app['guzzle.client'] = $client;

        new PackageManager($app);
    }

    public function testSetupJsonFail()
    {
        $app = new Application();
        $jsonManager = $this->getMockBuilder(JsonManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['update'])
            ->getMock()
        ;
        $jsonManager
            ->expects($this->once())
            ->method('update')
            ->willThrowException(new \Bolt\Filesystem\Exception\ParseException('bad'));
        $app['extend.manager.json'] = $jsonManager;
        $app['extend.writeable'] = true;
        $flashLogger = $this->getMockBuilder(FlashLogger::class)
            ->disableOriginalConstructor()
            ->setMethods(['danger'])
            ->getMock()
        ;
        $flashLogger
            ->expects($this->once())
            ->method('danger')
        ;
        $app['logger.flash'] = $flashLogger;

        new PackageManager($app);
    }

    public function providerSetupPingExceptions()
    {
        $app = new Application();
        $jsonManager = $this->getMockBuilder(JsonManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['update'])
            ->getMock()
        ;
        $jsonManager
            ->expects($this->any())
            ->method('update')
        ;
        $app['extend.manager.json'] = $jsonManager;
        $app['extend.writeable'] = true;
        $app['extend.site'] = 'https://example.com';
        $request = new Psr7\Request('GET', $app['extend.site']);

        return [
            [$app, new MockHandler([new ClientException('There was a 400', $request)]), '/^Client error: There was a 400/'],
            [$app, new MockHandler([new ServerException('There was a 500', $request)]), '/^Extension server returned an error: There was a 500/'],
            [$app, new MockHandler([new RequestException('DNS down', $request)]), '/^Testing connection to extension server failed: DNS down/'],
            [$app, new MockHandler([new Exception('Drop bear')]), '/^Generic failure while testing connection to extension server: Drop bear/'],
        ];
    }

    /**
     * @dataProvider providerSetupPingExceptions
     *
     * @param Application $app
     * @param MockHandler $mock
     * @param string      $regex
     */
    public function testSetupPingExceptions($app, $mock, $regex)
    {
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $app['guzzle.client'] = $client;

        $packageManager = new PackageManager($app);
        $messages = $packageManager->getMessages();

        $this->assertRegExp($regex, $messages[0]);
    }




    public function testGetAllPackages()
    {
        $installed = [
            ['package' => new CompletePackage('test/installed-a', '1.2.3.0', '1.2.3'), 'versions' => '1.2.3.0'],
            ['package' => new CompletePackage('test/installed-b', '2.4.6.0', '2.4.6'), 'versions' => '2.4.6.0'],
        ];
        $requires = [
            'test/required-a' => '^3.0',
            'test/required-b' => '^4.0',
        ];
        $app = new Application();
        $app['extend.writeable'] = false;
        $app['extend.action'] = $this->getActionMock('show', $installed);
        $extensions = $this->getMockBuilder(Manager::class)
            ->disableOriginalConstructor()
            ->setMethods(['getResolved'])
            ->getMock()
        ;
        $extensions
            ->expects($this->atLeastOnce())
            ->method('getResolved')
            ->willReturn(false)
        ;
        $app['extensions'] = $extensions;

        $packageManager = new PackageManager($app);

        $reflection = new \ReflectionClass(PackageManager::class);
        $method = $reflection->getProperty('json');
        $method->setAccessible(true);
        $method->setValue($packageManager, ['require' => $requires]);

        $packages = $packageManager->getAllPackages();

        $expected = '{"test\/installed-a":{"status":"installed","type":"library","name":"test\/installed-a","title":"test\/installed-a","description":null,"version":"1.2.3","authors":null,"keywords":null,"readmeLink":null,"configLink":null,"repositoryLink":null,"constraint":"4.0.0 alpha 1","valid":true,"enabled":true},"test\/installed-b":{"status":"installed","type":"library","name":"test\/installed-b","title":"test\/installed-b","description":null,"version":"2.4.6","authors":null,"keywords":null,"readmeLink":null,"configLink":null,"repositoryLink":null,"constraint":"4.0.0 alpha 1","valid":true,"enabled":true},"test\/required-a":{"status":"pending","type":"unknown","name":"test\/required-a","title":"test\/required-a","description":"Not yet installed.","version":"^3.0","authors":[],"keywords":[],"readmeLink":null,"configLink":null,"repositoryLink":null,"constraint":null,"valid":false,"enabled":false},"test\/required-b":{"status":"pending","type":"unknown","name":"test\/required-b","title":"test\/required-b","description":"Not yet installed.","version":"^4.0","authors":[],"keywords":[],"readmeLink":null,"configLink":null,"repositoryLink":null,"constraint":null,"valid":false,"enabled":false}}';

        $this->assertSame($expected, json_encode($packages));
    }

    public function providerActions()
    {
        return [
            ['checkPackage', 'check', []],
            ['dependsPackage', 'depends', [null, null]],
            ['dumpAutoload', 'autoload', []],
            ['installPackages', 'install', []],
            ['prohibitsPackage', 'prohibits', [null, null]],
            ['removePackage', 'remove', [[]]],
            ['requirePackage', 'require', [[]]],
            ['searchPackage', 'search', [[]]],
            ['showPackage', 'show', [null]],
            ['updatePackage', 'update', [[]]],
        ];
    }

    /**
     * @dataProvider providerActions
     *
     * @param string $method
     * @param string $action
     * @param array  $args
     */
    public function testActionCalls($method, $action, array $args)
    {
        $app = new Application();
        $app['extend.writeable'] = false;
        $app['extend.action'] = $this->getActionMock($action, true);

        $packageManager = new PackageManager($app);
        $mockResult = call_user_func_array([$packageManager, $method], $args);

        $this->assertTrue($mockResult);
    }

    public function testGetOutput()
    {
        $app = new Application();
        $app['extend.writeable'] = false;
        $mock = $this->getMockBuilder(\stdClass::class)
            ->disableOriginalConstructor()
            ->setMethods(['getOutput'])
            ->getMock()
        ;
        $mock
            ->expects($this->once())
            ->method('getOutput')
            ->willReturn('gum leaves')
        ;
        $app['extend.action.io'] = $mock;

        $packageManager = new PackageManager($app);
        $output = $packageManager->getOutput();

        $this->assertSame('gum leaves', $output);
    }

    public function testInitJson()
    {
        $app = new Application();
        $app['extend.writeable'] = false;
        $mock = $this->getMockBuilder(\stdClass::class)
            ->disableOriginalConstructor()
            ->setMethods(['init'])
            ->getMock()
        ;
        $mock
            ->expects($this->once())
            ->method('init')
            ->willReturn('gum leaves')
        ;
        $app['extend.manager.json'] = $mock;

        $packageManager = new PackageManager($app);
        $packageManager->initJson('composer.json', []);
    }

    public function testUseSsl()
    {
        $app = new Application();
        $app['extend.writeable'] = false;
        $app['guzzle.api_version'] = 6;

        $app['extend.site'] = 'http://example.com';
        $packageManager = new PackageManager($app);
        $this->assertFalse($packageManager->useSsl());

        $app['extend.site'] = 'https://example.com';
        $packageManager = new PackageManager($app);
        $this->assertTrue($packageManager->useSsl());
        // Test early return
        $this->assertTrue($packageManager->useSsl());
    }

    public function testUseInvalidCa()
    {
        $app = new Application();
        $app['extend.writeable'] = false;

        $app['extend.site'] = 'https://example.com';
        $packageManager = new PackageManager($app);

        //$this->assertFalse($packageManager->useSsl());
        //$this->assertSame(['Drop bear alert'], $packageManager->getMessages());
    }

    /**
     * @param string $action
     * @param mixed  $returnValue
     *
     * @return array
     */
    protected function getActionMock($action, $returnValue)
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->disableOriginalConstructor()
            ->setMethods(['execute'])
            ->getMock()
        ;
        $mock
            ->expects($this->once())
            ->method('execute')
            ->willReturn($returnValue)
        ;

        return [$action => $mock];
    }
}
