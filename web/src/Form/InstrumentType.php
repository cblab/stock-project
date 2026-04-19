<?php

namespace App\Form;

use App\Entity\Instrument;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InstrumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('inputTicker', TextType::class)
            ->add('providerTicker', TextType::class)
            ->add('displayTicker', TextType::class)
            ->add('name', TextType::class, ['required' => false])
            ->add('wkn', TextType::class, ['required' => false])
            ->add('isin', TextType::class, ['required' => false])
            ->add('assetClass', TextType::class)
            ->add('region', TextType::class, ['required' => false])
            ->add('benchmark', TextType::class, ['required' => false])
            ->add('contextType', TextType::class, ['required' => false])
            ->add('regionExposure', TextareaType::class, ['required' => false, 'help' => 'Ein Wert pro Zeile oder kommagetrennt.'])
            ->add('sectorProfile', TextareaType::class, ['required' => false, 'help' => 'Ein Wert pro Zeile oder kommagetrennt.'])
            ->add('topHoldingsProfile', TextareaType::class, ['required' => false, 'help' => 'Ein Wert pro Zeile oder kommagetrennt.'])
            ->add('macroProfile', TextareaType::class, ['required' => false, 'help' => 'Ein Wert pro Zeile oder kommagetrennt.'])
            ->add('mappingNote', TextareaType::class, ['required' => false])
            ->add('directNewsWeight', NumberType::class, ['required' => false])
            ->add('contextNewsWeight', NumberType::class, ['required' => false])
            ->add('active', CheckboxType::class, ['required' => false])
            ->add('isPortfolio', CheckboxType::class, ['required' => false]);

        foreach (['regionExposure', 'sectorProfile', 'topHoldingsProfile', 'macroProfile'] as $field) {
            $builder->get($field)->addModelTransformer(new CallbackTransformer(
                static fn (?array $values): string => implode("\n", $values ?? []),
                static fn (?string $value): array => array_values(array_filter(array_map(
                    static fn (string $item): string => trim($item),
                    preg_split('/[\r\n,]+/', $value ?? '') ?: []
                )))
            ));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Instrument::class]);
    }
}
