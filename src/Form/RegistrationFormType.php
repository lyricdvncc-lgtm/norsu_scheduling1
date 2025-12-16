<?php

namespace App\Form;

use App\Entity\User;
use App\Entity\College;
use App\Entity\Department;
use App\Repository\CollegeRepository;
use App\Repository\DepartmentRepository;
use App\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

class RegistrationFormType extends AbstractType
{
    private CollegeRepository $collegeRepository;
    private DepartmentRepository $departmentRepository;
    private UserRepository $userRepository;

    public function __construct(CollegeRepository $collegeRepository, DepartmentRepository $departmentRepository, UserRepository $userRepository)
    {
        $this->collegeRepository = $collegeRepository;
        $this->departmentRepository = $departmentRepository;
        $this->userRepository = $userRepository;
    }
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'First Name',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter your first name'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please enter your first name',
                    ]),
                    new Length([
                        'min' => 2,
                        'max' => 100,
                        'minMessage' => 'Your first name should be at least {{ limit }} characters',
                        'maxMessage' => 'Your first name cannot be longer than {{ limit }} characters',
                    ]),
                ],
            ])
            ->add('middleName', TextType::class, [
                'label' => 'Middle Name',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter your middle name (optional)'
                ],
                'required' => false,
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last Name',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter your last name'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please enter your last name',
                    ]),
                    new Length([
                        'min' => 2,
                        'max' => 100,
                        'minMessage' => 'Your last name should be at least {{ limit }} characters',
                        'maxMessage' => 'Your last name cannot be longer than {{ limit }} characters',
                    ]),
                ],
            ])
            ->add('username', TextType::class, [
                'label' => 'Username',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Choose a username'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please choose a username',
                    ]),
                    new Length([
                        'min' => 3,
                        'max' => 100,
                        'minMessage' => 'Your username should be at least {{ limit }} characters',
                        'maxMessage' => 'Your username cannot be longer than {{ limit }} characters',
                    ]),
                ],
            ])
            ->add('employeeId', TextType::class, [
                'label' => 'Employee ID',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter your employee ID (e.g., 202203633 or EMP123ABC)'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please enter your employee ID',
                    ]),
                    new Length([
                        'min' => 6,
                        'max' => 15,
                        'minMessage' => 'Employee ID must be at least {{ limit }} characters',
                        'maxMessage' => 'Employee ID cannot exceed {{ limit }} characters',
                    ]),
                    new Callback([
                        'callback' => function($value, ExecutionContextInterface $context) {
                            if ($value) {
                                $existingUser = $this->userRepository->findOneBy(['employeeId' => $value]);
                                if ($existingUser) {
                                    $context->buildViolation('This Employee ID is already registered in the system.')
                                        ->addViolation();
                                }
                            }
                        }
                    ]),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter your email address'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please enter your email address',
                    ]),
                ],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Address',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter your address',
                    'rows' => 3
                ],
                'required' => false,
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
                'attr' => [
                    'class' => 'form-select',
                    'id' => 'college-select-register'
                ],
                'label' => 'College',
                'placeholder' => 'Select your college',
                'required' => true,
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please select your college',
                    ]),
                ],
            ])
            ->add('department', EntityType::class, [
                'class' => Department::class,
                'choice_label' => 'name',
                'choice_value' => 'id',
                'query_builder' => function() {
                    return $this->departmentRepository->createQueryBuilder('d')
                        ->leftJoin('d.college', 'c')
                        ->where('d.isActive = :active')
                        ->andWhere('d.deletedAt IS NULL')
                        ->andWhere('c.isActive = :active')
                        ->andWhere('c.deletedAt IS NULL')
                        ->andWhere('d.college IS NOT NULL') // Only departments with a college
                        ->setParameter('active', true)
                        ->orderBy('c.name', 'ASC')
                        ->addOrderBy('d.name', 'ASC');
                },
                'attr' => [
                    'class' => 'form-select',
                    'id' => 'department-select-register'
                ],
                'label' => 'Department',
                'placeholder' => 'Select your department',
                'required' => true,
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please select your department',
                    ]),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'The password fields must match.',
                'options' => ['attr' => ['class' => 'form-input']],
                'required' => true,
                'first_options' => [
                    'label' => 'Password',
                    'attr' => ['placeholder' => 'Choose a password']
                ],
                'second_options' => [
                    'label' => 'Confirm Password',
                    'attr' => ['placeholder' => 'Confirm your password']
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Please enter a password',
                    ]),
                    new Length([
                        'min' => 6,
                        'minMessage' => 'Your password should be at least {{ limit }} characters',
                        'max' => 4096,
                    ]),
                ],
                'mapped' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}