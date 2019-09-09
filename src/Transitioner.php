<?php declare(strict_types=1);

namespace Aeviiq\FormFlow;

use Aeviiq\FormFlow\Enum\Transition\Request;
use Aeviiq\FormFlow\Enum\Transition\Status;
use Aeviiq\FormFlow\Event\CompletedEvent;
use Aeviiq\FormFlow\Event\Event;
use Aeviiq\FormFlow\Event\ResetEvent;
use Aeviiq\FormFlow\Event\TransitionedEvent;
use Aeviiq\FormFlow\Event\TransitionEvent;
use Aeviiq\FormFlow\Exception\LogicException;
use Aeviiq\FormFlow\Exception\TransitionException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\RequestStack;

final class Transitioner implements TransitionerInterface, RequestStackAwareInterface
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var EventDispatcherInterface|EventDispatcher
     */
    private $dispatcher;

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * {@inheritDoc}
     */
    public function hasTransitionRequest(FormFlowInterface $flow): bool
    {
        return '' !== $this->getHttpRequest()->get($flow->getTransitionKey(), '');
    }

    /**
     * {@inheritDoc}
     */
    public function transition(FormFlowInterface $flow): Status
    {
        if (!$this->hasTransitionRequest($flow)) {
            throw new TransitionException($flow, \sprintf(
                'Unable to transition flow "%s". Use TransitionerInterface#hasTransitionRequest() to ensure there is a transition request before attempting to transition.',
                $flow->getName()
            ));
        }

        $request = Request::createByHttpRequestAndFlow($this->getHttpRequest(), $flow);
        switch ($request->getValue()) {
            case (Request::FORWARDS):
                // For now, we don't support multiple forwards transitions.
                $status = $this->forwards($flow);
                break;
            case (Request::BACKWARDS):
                $currentStepNumber = $flow->getCurrentStepNumber();
                $requestedStepNumber = $request->getRequestedStepNumber();
                if ($currentStepNumber <= $requestedStepNumber || $requestedStepNumber > $flow->getSteps()->count()) {
                    throw new TransitionException($flow, \sprintf('"%s" is an invalid requested step number in the current context.', $requestedStepNumber));
                }

                while ($flow->getCurrentStepNumber() > $requestedStepNumber) {
                    $status = $this->backwards($flow);
                    if (!$status->isSuccessful()) {
                        break;
                    }
                }
                break;
            case (Request::COMPLETE):
                $status = $this->complete($flow);
                break;
            case (Request::RESET):
                $status = $this->reset($flow);
                break;
            default:
                $status = new Status(Status::FAILURE);
        }

        return $status;
    }

    /**
     * {@inheritDoc}
     */
    public function forwards(FormFlowInterface $flow): Status
    {
        $currentStep = $flow->getCurrentStep();
        if ($flow->getCurrentStep() === $flow->getLastStep()) {
            throw new TransitionException($flow, 'The flow is on the last step and can not transition forwards.');
        }

        $form = $flow->getCurrentStepForm();
        $this->submitForm($form);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return new Status(Status::FAILURE | Status::INVALID_FORM);
        }

        $currentStepNumber = $currentStep->getNumber();
        $event = new TransitionEvent($flow);
        $this->dispatch($event, FormFlowEvents::PRE_FORWARDS, $flow, $currentStepNumber);
        if ($event->isTransitionBlocked()) {
            return new Status(Status::FAILURE | Status::VALID_FORM | Status::BLOCKED);
        }

        $context = $flow->getContext();
        $context->setCurrentStepNumber($currentStepNumber + 1);
        $context->markCompleted($currentStep);
        $this->dispatch(new TransitionedEvent($flow), FormFlowEvents::FORWARDS, $flow, $currentStepNumber);
        $flow->save();

        return new Status(Status::SUCCESS | Status::VALID_FORM);
    }

    /**
     * {@inheritDoc}
     */
    public function backwards(FormFlowInterface $flow): Status
    {
        $currentStep = $flow->getCurrentStep();
        if ($flow->getCurrentStep() === $flow->getFirstStep()) {
            throw new TransitionException($flow, 'The flow is on the first step and can not transition backwards.');
        }

        $form = $flow->getCurrentStepForm();
        $this->submitForm($form);
        $status = !$form->isSubmitted() || !$form->isValid() ? Status::INVALID_FORM : Status::VALID_FORM;

        $currentStepNumber = $currentStep->getNumber();
        $event = new TransitionEvent($flow);
        $this->dispatch($event, FormFlowEvents::PRE_BACKWARDS, $flow, $currentStepNumber);
        if ($event->isTransitionBlocked()) {
            return new Status(Status::FAILURE | Status::BLOCKED | $status);
        }

        $context = $flow->getContext();
        $context->markIncompleted($currentStep);
        $context->setCurrentStepNumber($currentStepNumber - 1);
        $this->dispatch(new TransitionedEvent($flow), FormFlowEvents::BACKWARDS, $flow, $currentStepNumber);
        $flow->save();

        return new Status(Status::SUCCESS | Status::VALID_FORM);
    }

    /**
     * {@inheritDoc}
     */
    public function complete(FormFlowInterface $flow): Status
    {
        if ($flow->getCurrentStep() !== $flow->getLastStep()) {
            throw new TransitionException($flow, 'The flow must be on the last step in order to be completed.');
        }

        $form = $flow->getCurrentStepForm();
        $this->submitForm($form);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return new Status(Status::FAILURE | Status::INVALID_FORM);
        }

        $context = $flow->getContext();
        foreach ($flow->getSteps()->filterStepsSmallerThanNumber($flow->getCurrentStepNumber()) as $previousStep) {
            if (!$context->isCompleted($previousStep)) {
                return new Status(Status::FAILURE);
            }
        }

        $event = new TransitionEvent($flow);
        $this->dispatch($event, FormFlowEvents::PRE_COMPLETE, $flow);
        if ($event->isTransitionBlocked()) {
            return new Status(Status::FAILURE | Status::VALID_FORM | Status::BLOCKED);
        }

        $this->dispatch(new CompletedEvent($flow), FormFlowEvents::COMPLETED, $flow);
        $flow->reset();

        return new Status(Status::SUCCESS | Status::VALID_FORM | Status::COMPLETED);
    }

    /**
     * {@inheritDoc}
     */
    public function reset(FormFlowInterface $flow): Status
    {
        $this->dispatcher->dispatch(new ResetEvent($flow), FormFlowEvents::RESET);
        $flow->reset();

        return new Status(Status::SUCCESS | Status::RESET);
    }

    public function setRequestStack(RequestStack $requestStack): void
    {
        $this->requestStack = $requestStack;
    }

    /**
     * Fires up to 3 'sub' events per given event.
     */
    private function dispatch(Event $event, string $eventName, FormFlowInterface $flow, ?int $currentStepNumber = null): void
    {
        if (null !== $currentStepNumber) {
            $this->dispatcher->dispatch($event, $this->createListenerStepId($eventName, $flow, $currentStepNumber));
        }

        $this->dispatcher->dispatch($event, $this->createFlowListener($eventName, $flow));
        $this->dispatcher->dispatch($event, $eventName);
    }

    private function createListenerStepId(string $eventName, FormFlowInterface $flow, int $stepNumber): string
    {
        return \sprintf('%s.%s.step_%s', $eventName, $flow->getName(), $stepNumber);
    }

    private function createFlowListener(string $eventName, FormFlowInterface $flow): string
    {
        return \sprintf('%s.%s', $eventName, $flow->getName());
    }

    private function submitForm(FormInterface $form): void
    {
        if (!$form->isSubmitted()) {
            $form->handleRequest($this->getHttpRequest());
        }
    }

    private function getHttpRequest(): HttpRequest
    {
        if (null === $this->requestStack || null === $request = $this->requestStack->getCurrentRequest()) {
            throw new LogicException('No request available.');
        }

        return $request;
    }
}
