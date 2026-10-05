<?php

namespace Base\Office\Form;

use Base\Form\Type\PrivacyType;
use Base\Office\Form\Model\AppointmentRequest;
use Base\Office\Entity\Booking\AppointmentType;
use Base\Office\Entity\Member;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The first appointment request, open to someone who has no account: what
 * it is about (a type in request mode), with whom if they know, how to
 * reach them, a few words - and omnibase's data-protection notice with its
 * box to tick (Base\Form\Type\PrivacyType).
 *
 * Its labels are translation keys under `label_prefix`
 * ('@office.appointment_request' by default): type, choose, member,
 * no_preference, name, email, phone, message, message_help, privacy. A
 * regime that words them its own way gives its prefix ('@notary.request').
 */
class AppointmentRequestType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AppointmentRequest::class,
            'types' => [],
            'members' => [],
            'privacy_parameters' => [],
            'label_prefix' => '@office.appointment_request',
        ]);
        $resolver->setAllowedTypes('label_prefix', 'string');
        $resolver->setAllowedTypes('types', 'array');
        $resolver->setAllowedTypes('members', 'array');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $p = rtrim($options['label_prefix'], '.').'.';
        $builder
            ->add('type', ChoiceType::class, [
                'label' => $p.'type',
                'choices' => $options['types'],
                'choice_label' => static fn (AppointmentType $type): string => $type->getName(),
                'choice_value' => static fn (?AppointmentType $type): string => (string) $type?->getId(),
                'choice_translation_domain' => false,
                'placeholder' => 1 === \count($options['types']) ? false : $p.'choose',
            ])
            ->add('member', ChoiceType::class, [
                'label' => $p.'member',
                'required' => false,
                'choices' => $options['members'],
                'choice_label' => static fn (Member $member): string => $member->getDisplayName(),
                'choice_value' => static fn (?Member $member): string => (string) $member?->getId(),
                'choice_translation_domain' => false,
                'placeholder' => $p.'no_preference',
            ])
            ->add('name', TextType::class, ['label' => $p.'name', 'attr' => ['autocomplete' => 'name']])
            ->add('email', EmailType::class, ['label' => $p.'email', 'attr' => ['autocomplete' => 'email']])
            ->add('phone', TelType::class, ['label' => $p.'phone', 'attr' => ['autocomplete' => 'tel']])
            ->add('message', TextareaType::class, ['label' => $p.'message', 'required' => false, 'help' => $p.'message_help', 'attr' => ['rows' => 4, 'maxlength' => 1000]])
            // Off-screen for people (and for screen readers), filled by robots.
            ->add('website', TextType::class, ['required' => false, 'label' => false,
                'row_attr' => ['class' => 'base-trap', 'aria-hidden' => 'true', 'style' => 'position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden'],
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off']])
            ->add('privacy', PrivacyType::class, [
                'notice' => $p.'privacy',
                'notice_parameters' => $options['privacy_parameters'],
                'consent' => true,
            ]);
    }
}
