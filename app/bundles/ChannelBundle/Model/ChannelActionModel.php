<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\ChannelBundle\Model;

use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Component\Translation\TranslatorInterface;

class ChannelActionModel
{
    /**
     * @var LeadModel
     */
    private $contactModel;

    /**
     * @var DoNotContact
     */
    private $doNotContact;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(
        LeadModel $contactModel,
        DoNotContact $doNotContact,
        TranslatorInterface $translator
    ) {
        $this->contactModel = $contactModel;
        $this->doNotContact = $doNotContact;
        $this->translator   = $translator;
    }

    /**
     * Update channels and frequency rules.
     */
    public function update(array $contactIds, array $subscribedChannels)
    {
        $contacts = $this->contactModel->getLeadsByIds($contactIds);

        foreach ($contacts as $contact) {
            if (!$this->contactModel->canEditContact($contact)) {
                continue;
            }

            $this->addChannels($contact, $subscribedChannels);
            $this->removeChannels($contact, $subscribedChannels);
        }
    }

    /**
     * Get contact channels.
     *
     * @return array
     */
    public function getContactChannels(Lead $lead)
    {
        $allChannels = $this->contactModel->getPreferenceChannels();
        if (null == $allChannels) {
            $allChannels = [];
        }

        $channels = [];
        foreach ($allChannels as $channel) {
            if (DNC::IS_CONTACTABLE === $this->contactModel->isContactable($lead, $channel)) {
                $channels[$channel] = $channel;
            }
        }

        return $channels;
    }

    /**
     * Add contact's channels.
     * Only resubscribe if the contact did not opt out themselves.
     */
    private function addChannels(Lead $contact, array $subscribedChannels)
    {
        $allChannels = $this->contactModel->getPreferenceChannels();
        if (null == $allChannels) {
            $allChannels = [];
        }

        $contactChannels = [];
        foreach ($allChannels as $channel) {
            if (DNC::IS_CONTACTABLE === $this->doNotContact->isContactable($contact, $channel)) {
                $channels[$channel] = $channel;
            }
        }

        foreach ($subscribedChannels as $subscribedChannel) {
            if (!array_key_exists($subscribedChannel, $contactChannels)) {
                $contactable = $this->doNotContact->isContactable($contact, $subscribedChannel);
                if (DNC::UNSUBSCRIBED !== $contactable) {
                    $this->doNotContact->removeDncForContact($contact->getId(), $subscribedChannel);
                }
            }
        }
    }

    /**
     * Remove contact's channels.
     */
    private function removeChannels(Lead $contact, array $subscribedChannels)
    {
        $allChannels = $this->contactModel->getPreferenceChannels();
        $dncChannels = array_diff($allChannels, $subscribedChannels);

        if (empty($dncChannels)) {
            return;
        }

        foreach ($dncChannels as $channel) {
            $this->doNotContact->addDncForContact(
                $contact->getId(),
                $channel,
                DNC::MANUAL,
                $this->translator->trans('mautic.lead.event.donotcontact_manual')
            );
        }
    }
}
