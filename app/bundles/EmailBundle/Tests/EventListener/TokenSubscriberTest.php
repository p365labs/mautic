<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\TemplatingHelper;
use Mautic\CoreBundle\Helper\ThemeHelper;
use Mautic\CoreBundle\Templating\Helper\SlotsHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\EventListener\TokenSubscriber;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\MonitoredEmail\Mailbox;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\PrimaryCompanyHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

class TokenSubscriberTest extends \PHPUnit\Framework\TestCase
{
    public function testDynamicContentCustomTokens()
    {
        $mockFactory          = $this->createMock(ModelFactory::class);
        $coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $themeHelper          = $this->createMock(ThemeHelper::class);
        $em                   = $this->createMock(EntityManagerInterface::class);
        $mailbox              = $this->createMock(Mailbox::class);
        $templateHelper       = $this->createMock(TemplatingHelper::class);
        $swiftTransport       = $this->createMock(\Swift_Transport::class);
        $dispatcher           = $this->createMock(EventDispatcher::class);
        $logger               = $this->createMock(LoggerInterface::class);
        $router               = $this->createMock(RouterInterface::class);
        $slotHelper           = $this->createMock(SlotsHelper::class);
        $request              = $this->createMock(RequestStack::class);

        $swiftMailer = $this->createMock(\Swift_Mailer::class);

        $tokens = [
            '{test}' => 'value',
        ];

        $mailHelper = new MailHelper(
            $mockFactory,
            $swiftMailer,
            $coreParametersHelper,
            $themeHelper,
            $em,
            $mailbox,
            $templateHelper,
            $swiftTransport,
            $dispatcher,
            $logger,
            $router,
            $slotHelper,
            $request,
            ['nobody@nowhere.com' => 'No Body']
        );
        $mailHelper->setTokens($tokens);

        $email = new Email();
        $email->setCustomHtml(
            <<<'CONTENT'
<html xmlns="http://www.w3.org/1999/xhtml">
    <body style="margin: 0px; cursor: auto;" class="ui-sortable">
        <div data-section-wrapper="1">
            <center>
                <table data-section="1" style="width: 600;" width="600" cellpadding="0" cellspacing="0">
                    <tbody>
                        <tr>
                            <td>
                                <div data-slot-container="1" style="min-height: 30px">
                                    <div data-slot="text"><br /><h2>Hello there!</h2><br />{test} test We haven't heard from you for a while...<a href="https://google.com">check this link</a><br /><br />{unsubscribe_text} | {webview_text}</div>{dynamiccontent="Dynamic Content 2"}<div data-slot="codemode">
                                    <div id="codemodeHtmlContainer">
    <p>Place your content here {test}</p></div>

                                </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </center>
        </div>
</body></html>
CONTENT
        )
            ->setDynamicContent(
                [
                    [
                        'tokenName' => 'Dynamic Content 1',
                        'content'   => 'Default Dynamic Content',
                        'filters'   => [
                            [
                                'content' => null,
                                'filters' => [
                                ],
                            ],
                        ],
                    ],
                    [
                        'tokenName' => 'Dynamic Content 2',
                        'content'   => 'DEC {test}',
                        'filters'   => [
                        ],
                    ],
                ]
            );
        $mailHelper->setEmail($email);

        $lead = new Lead();
        $lead->setEmail('hello@someone.com');
        $mailHelper->setLead($lead);

        $dispatcher           = new EventDispatcher();
        $primaryCompanyHelper = $this->createMock(PrimaryCompanyHelper::class);
        $primaryCompanyHelper->method('getProfileFieldsWithPrimaryCompany')
            ->willReturn(['email' => 'hello@someone.com']);

        /** @var TokenSubscriber $subscriber */
        $subscriber = $this->getMockBuilder(TokenSubscriber::class)
            ->setConstructorArgs([$dispatcher, $primaryCompanyHelper])
            ->setMethods(null)
            ->getMock();

        $dispatcher->addSubscriber($subscriber);

        $event = new EmailSendEvent($mailHelper);

        $subscriber->decodeTokens($event);

        $eventTokens = $event->getTokens(false);
        $this->assertEquals(
            $eventTokens,
            [
                '{dynamiccontent="Dynamic Content 1"}' => 'Default Dynamic Content',
                '{dynamiccontent="Dynamic Content 2"}' => 'DEC value',
            ]
        );
        $mailHelper->addTokens($eventTokens);
        $mailerTokens = $mailHelper->getTokens();
        $mailHelper->message->setBody($email->getCustomHtml());

        MailHelper::searchReplaceTokens(array_keys($mailerTokens), $mailerTokens, $mailHelper->message);
        $parsedBody = $mailHelper->message->getBody();

        $this->assertNotFalse(strpos($parsedBody, 'DEC value'));
        $this->assertNotFalse(strpos($parsedBody, 'value test We'));
        $this->assertNotFalse(strpos($parsedBody, 'Place your content here value'));
    }
}
