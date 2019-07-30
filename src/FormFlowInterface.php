<?php declare(strict_types=1);

namespace Aeviiq\FormFlow;

use Aeviiq\FormFlow\Step\StepCollection;
use Aeviiq\FormFlow\Step\StepInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;

// TODO implement these interfaces.
interface FormFlowInterface extends StartableInterface, TransitionableInterface, ResettableInterface, CompletableInterface//, BlockableInterface
{
    /**
     * @return string The unique name of the form flow.
     */
    public function getName(): string;

    public function setRequestStack(RequestStack $requestStack): void;

    /**
     * @return string The input name which should have the desired transition value (@see TransitionEnum) as value.
     */
    public function getTransitionKey(): string;

    /**
     * @return bool Whether or not the requested transition was successful.
     */
    public function transition(): bool;

    public function save(): void;

    public function getData(): object;

    public function isFormValid(): bool;

    public function getForm(): FormInterface;

    public function getCurrentStepNumber(): int;

    public function getSteps(): StepCollection;

    public function getCurrentStep(): StepInterface;

    public function getNextStep(): StepInterface;

    public function hasNextStep(): bool;

    public function getPreviousStep(): StepInterface;

    public function hasPreviousStep(): bool;

    public function getFirstStep(): StepInterface;

    public function getLastStep(): StepInterface;
}
