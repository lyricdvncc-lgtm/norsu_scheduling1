<?php

namespace App\Form;

use App\Entity\User;
use App\Entity\College;
use App\Entity\Department;
use App\Repository\CollegeRepository;
use App\Repository\DepartmentRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserEditFormType extends AbstractType
{
    private CollegeRepository $collegeRepository;
    private DepartmentRepository $departmentRepository;

    public function __construct(CollegeRepository $collegeRepository, DepartmentRepository $departmentRepository)
    {
        $this->collegeRepository = $collegeRepository;
        $this->departmentRepository = $departmentRepository;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isDepartmentHead = $options['is_department_head'];
        
        $builder
            ->add('username', TextType::class, [
                'label' => 'Username',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please enter a username',
                    ]),
                    new Length([
                        'min' => 3,
                        'minMessage' => 'Username should be at least {{ limit }} characters',
                        'max' => 255,
                    ]),
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'First Name',
                'required' => false,
            ])
            ->add('middleName', TextType::class, [
                'label' => 'Middle Name',
                'required' => false,
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last Name',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please enter an email address',
                    ]),
                ],
            ])
            ->add('employeeId', TextType::class, [
                'label' => 'Employee ID',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please enter an employee ID',
                    ]),
                    new Length([
                        'min' => 6,
                        'minMessage' => 'Employee ID should be at least {{ limit }} characters',
                        'max' => 15,
                        'maxMessage' => 'Employee ID cannot be longer than {{ limit }} characters',
                    ]),
                ],
            ])
            ->add('position', ChoiceType::class, [
                'label' => 'Position/Title',
                'choices' => [
                    'Full-time' => 'Full-time',
                    'Part-time' => 'Part-time',
                    'Regular' => 'Regular',
                    'Contractual' => 'Contractual',
                    'Visiting' => 'Visiting',
                ],
                'placeholder' => 'Select position/title',
                'required' => false,
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Address',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'Enter address'
                ],
            ])
            ->add('otherDesignation', TextareaType::class, [
                'label' => 'Other Designation/Special Assignments',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'Enter other designation or special assignments'
                ],
            ])
            ->add('role', ChoiceType::class, [
                'choices' => [
                    'Administrator' => 1,
                    'Department Head' => 2,
                    'Faculty' => 3,
                ],
                'label' => 'Role',
                'placeholder' => 'Select a role',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please select a role',
                    ]),
                ],
                'attr' => $isDepartmentHead ? ['disabled' => 'disabled'] : [], // Use HTML disabled attribute
            ])
            ->add('college', EntityType::class, [
                'class' => College::class,
                'choice_label' => 'name',
                'choice_value' => 'id',
                'query_builder' => function() {
                    return $this->collegeRepository->createQueryBuilder('c')
                        ->where('c.isActive = :active')
                        ->andWhere('c.deletedAt IS NULL')
                        ->setParameter('active', true)
                        ->orderBy('c.name', 'ASC');
                },
                'label' => 'College',
                'placeholder' => 'Select a college',
                'required' => false,
                'mapped' => true,
                'attr' => $isDepartmentHead ? ['disabled' => 'disabled'] : [], // Use HTML disabled attribute instead
            ]);

        // Add form event listener to dynamically filter departments based on college
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($isDepartmentHead) {
            $user = $event->getData();
            $form = $event->getForm();
            
            // Get the user's current college
            $college = $user ? $user->getCollege() : null;
            
            // Add department field with filtering (college field is now mapped, so it will populate automatically)
            $this->addDepartmentField($form, $college);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($isDepartmentHead) {
            $data = $event->getData();
            $form = $event->getForm();
            
            // Get the selected college from submitted data
            $collegeId = $data['college'] ?? null;
            $college = $collegeId ? $this->collegeRepository->find($collegeId) : null;
            
            // Update department field based on selected college
            $this->addDepartmentField($form, $college);
        });

        $builder->add('isActive', CheckboxType::class, [
            'label' => 'Active User',
            'required' => false,
        ]);

        // Optional password reset field
        if ($options['include_password_reset']) {
            $builder->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => false,
                'first_options' => [
                    'label' => 'New Password',
                    'constraints' => [
                        new Length([
                            'min' => 6,
                            'minMessage' => 'Password should be at least {{ limit }} characters',
                            'max' => 4096,
                        ]),
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirm New Password',
                ],
                'invalid_message' => 'The password fields must match.',
            ]);
        }
    }

    private function addDepartmentField(FormInterface $form, ?College $college): void
    {
        // Get the form options to check if this is for department head
        $options = $form->getConfig()->getOptions();
        $isDepartmentHead = $options['is_department_head'] ?? false;
        
        $form->add('department', EntityType::class, [
            'class' => Department::class,
            'choice_label' => 'name',
            'choice_value' => 'id',
            'query_builder' => function() use ($college) {
                $qb = $this->departmentRepository->createQueryBuilder('d')
                    ->where('d.isActive = :active')
                    ->andWhere('d.deletedAt IS NULL')
                    ->setParameter('active', true);
                
                // If a college is selected, filter departments by that college
                if ($college) {
                    $qb->andWhere('d.college = :college')
                       ->setParameter('college', $college);
                }
                
                return $qb->orderBy('d.name', 'ASC');
            },
            'label' => 'Department',
            'placeholder' => 'Select a department',
            'required' => false,
            'attr' => $isDepartmentHead ? ['disabled' => 'disabled'] : [], // Use HTML disabled attribute
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'include_password_reset' => false,
            'is_department_head' => false,
        ]);
    }
}