<?php

namespace Base\Office\DependencyInjection;

use Base\Bundle\AbstractBaseConfiguration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class OfficeConfiguration extends AbstractBaseConfiguration
{
    private bool $childrenDeclared = false;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = $this->getTreeBuilder();
        if ($this->childrenDeclared) {
            return $treeBuilder;
        }
        $this->childrenDeclared = true;

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('timezone')->defaultValue('Europe/Paris')
                    ->info('The office\'s timezone: schedules are written in it, slots are given in it.')->end()
                ->scalarNode('recipient')->defaultValue('%env(MAILER_CONTACT)%')
                    ->info('Where a contact request is sent.')->end()
                ->scalarNode('sender')->defaultValue('%env(MAILER_TECHNICAL)%')
                    ->info('The From of the e-mails the office sends (confirmations, reminders, "a document awaits").')->end()
                ->arrayNode('contact')->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('notice')->defaultValue('@office.contact.notice')
                            ->info('The warning above the contact form, a translation key: the regime chooses it (health: no medical data here, use your space).')->end()
                        ->integerNode('retention_months')->min(1)->defaultValue(12)->end()
                    ->end()
                ->end()
                ->arrayNode('templates')->addDefaultsIfNotSet()
                    ->info('The public pages, a regime may give its own.')
                    ->children()
                        ->scalarNode('team')->defaultValue('@Office/client/team.html.twig')->end()
                        ->scalarNode('member')->defaultValue('@Office/client/member.html.twig')->end()
                        ->scalarNode('hours')->defaultValue('@Office/client/hours.html.twig')->end()
                        ->scalarNode('contact')->defaultValue('@Office/client/contact.html.twig')->end()
                        ->scalarNode('space_nav')->defaultNull()
                            ->info('A partial included at the top of the client\'s pages (appointments, documents): the regime\'s menu of the space.')->end()
                        ->scalarNode('layout')->defaultValue('base.html.twig')
                            ->info('The template the pages extend.')->end()
                    ->end()
                ->end()
                ->arrayNode('booking')->addDefaultsIfNotSet()->canBeDisabled()
                    ->children()
                        ->integerNode('min_notice')->min(0)->defaultValue(120)
                            ->info('Minutes: a slot closer than this cannot be booked online.')->end()
                        ->integerNode('horizon')->min(1)->defaultValue(60)
                            ->info('Days ahead a slot can be booked online.')->end()
                        ->integerNode('cancel_until')->min(0)->defaultValue(24)
                            ->info('Hours before the appointment the client may still cancel online.')->end()
                        ->integerNode('step')->min(0)->defaultValue(0)
                            ->info('Minutes between two slot starts; 0: the appointment type\'s duration plus its buffer.')->end()
                        ->arrayNode('reminders')
                            ->info('When reminders are sent: hours before the appointment.')
                            ->integerPrototype()->end()
                            ->defaultValue([24, 2])
                        ->end()
                        ->scalarNode('space_route')->defaultValue('office_space_appointments')
                            ->info('The client\'s list of appointments, linked from the e-mails.')->end()
                    ->end()
                ->end()
                ->arrayNode('share')->addDefaultsIfNotSet()->canBeDisabled()
                    ->children()
                        ->scalarNode('storage')->defaultValue('local.share')
                            ->info('The Flysystem storage the encrypted files are written to: private, never under public/.')->end()
                        ->scalarNode('master_key')->defaultValue('%env(default::SHARE_MASTER_KEY)%')
                            ->info('Base64 of 32 bytes, from the secrets vault. Missing: nothing is stored or read (fail closed).')->end()
                        ->integerNode('max_size')->min(1)->defaultValue(20 * 1024 * 1024)->end()
                        ->arrayNode('mime_types')
                            ->scalarPrototype()->end()
                            ->defaultValue(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'text/plain'])
                        ->end()
                        ->scalarNode('space_route')->defaultValue('office_space_documents')
                            ->info('Where the "a document awaits" e-mail sends the recipient (sign-in required).')->end()
                    ->end()
                ->end()
                ->arrayNode('visio')->addDefaultsIfNotSet()->canBeDisabled()
                    ->children()
                        ->integerNode('open_before')->min(0)->defaultValue(10)
                            ->info('Minutes before the appointment the room opens.')->end()
                        ->integerNode('close_after')->min(0)->defaultValue(30)
                            ->info('Minutes after its planned end the room closes.')->end()
                        ->scalarNode('turn_secret')->defaultValue('%env(default::TURN_SECRET)%')
                            ->info('coturn\'s static-auth-secret: the temporary TURN credentials are signed with it.')->end()
                        ->arrayNode('turn_urls')
                            ->beforeNormalization()->ifString()->then(static fn (string $v) => array_values(array_filter(array_map('trim', explode(',', $v)))))->end()
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('stun_urls')
                            ->beforeNormalization()->ifString()->then(static fn (string $v) => array_values(array_filter(array_map('trim', explode(',', $v)))))->end()
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->integerNode('turn_ttl')->min(60)->defaultValue(3600)->end()
                        ->scalarNode('turn_probe')->defaultNull()
                            ->info('host:port the compliance check asks instead of the first TURN URL, when the server does not reach the relay by the address browsers use (a container\'s service name).')->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
