<?php

namespace App\Form;

use App\Entity\Room;
use App\Entity\Department;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RoomFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Room Code',
                'attr' => [
                    'class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500',
                    'placeholder' => 'e.g., R101, LAB-201'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Room code is required']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'Room code cannot be longer than {{ limit }} characters'
                    ])
                ]
            ])
            ->add('name', TextType::class, [
                'label' => 'Room Name',
                'attr' => [
                    'class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500',
                    'placeholder' => 'e.g., Computer Laboratory 1'
                ],
                'required' => false,
                'constraints' => [
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'Room name cannot be longer than {{ limit }} characters'
                    ])
                ]
            ])
            ->add('department', EntityType::class, [
                'class' => Department::class,
                'choice_label' => 'name',
                'label' => 'Department',
                'placeholder' => 'Select department',
                'attr' => [
                    'class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Department is required'])
                ]
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Room Type',
                'choices' => [
                    'Select room type' => '',
                    'Classroom' => 'classroom',
                    'Laboratory' => 'laboratory',
                    'Auditorium' => 'auditorium',
                    'Office' => 'office',
                ],
                'attr' => [
                    'class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500'
                ],
                'required' => false
            ])
            ->add('capacity', IntegerType::class, [
                'label' => 'Capacity',
                'attr' => [
                    'class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500',
                    'placeholder' => 'Number of people',
                    'min' => 1
                ],
                'required' => false,
                'constraints' => [
                    new Assert\Positive(['message' => 'Capacity must be a positive number'])
                ]
            ])
            ->add('building', TextType::class, [
                'label' => 'Building',
                'attr' => [
                    'class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500',
                    'placeholder' => 'e.g., Main Building, Science Building'
                ],
                'required' => false
            ])
            ->add('floor', TextType::class, [
                'label' => 'Floor',
                'attr' => [
                    'class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500',
                    'placeholder' => 'e.g., 1st Floor, 2nd Floor, Ground'
                ],
                'required' => false
            ])
            ->add('equipment', TextareaType::class, [
                'label' => 'Equipment / Facilities',
                'attr' => [
                    'class' => 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500',
                    'placeholder' => 'List available equipment and facilities (e.g., Projector, Whiteboard, Air Conditioning, Computers)',
                    'rows' => 4
                ],
                'required' => false
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Active',
                'required' => false,
                'attr' => [
                    'class' => 'rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500'
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Room::class,
        ]);
    }
}
