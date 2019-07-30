<?php declare(strict_types = 1);

namespace Aeviiq\FormFlow;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;

// TODO implement these interfaces.
interface FormFlowInterface extends StartableInterface, TransitionableInterface, SteppableInterface, ResettableInterface, CompletableInterface//, BlockableInterface
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
}
