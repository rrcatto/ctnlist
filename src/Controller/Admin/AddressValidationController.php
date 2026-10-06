<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\CattoMail\AddressValidation;
use App\CattoMail\CattoMailConfig;
use App\CattoMail\CattoMailException;
use App\Form\Type\ValidationRequestType;
use App\Http\Pagination;
use App\Repository\CattoMailValidationRepository;
use App\Repository\ListRepository;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Address validation through catto-mail: start jobs for a list's members and
 * read the results as catto-mail classified them. Results never change a
 * subscriber automatically (a suggestion is only shown).
 */
#[IsGranted('subscribers.manage')]
final class AddressValidationController extends AbstractController
{
    /** catto-mail's overall classifications, in the order shown. */
    public const CLASSIFICATIONS = ['deliverable', 'probably_deliverable', 'risky', 'unknown', 'temporarily_unverifiable', 'undeliverable'];

    public function __construct(
        private readonly CattoMailValidationRepository $validations,
        private readonly ListRepository $lists,
    ) {
    }

    #[Route('/address-validation', name: 'admin_validation', methods: ['GET', 'POST'])]
    public function index(Request $request, AddressValidation $validation, CattoMailConfig $config, #[CurrentUser] SubscriberUser $user): Response
    {
        $lists = $this->lists->all();
        $form = $this->createForm(ValidationRequestType::class, ['listId' => $lists[0]['l_id'] ?? null], ['lists' => $lists]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{listId: int} $data */
            $data = $form->getData();
            $list = $this->lists->findById($data['listId']);
            try {
                $ids = $list === null ? [] : $validation->createForList($list['l_id'], $list['l_name'], $user->id);
                $this->addFlash('info', $ids === [] ? 'The list has no members to validate.' : count($ids) . ' validation job(s) created.');
                return $ids === [] ? $this->redirectToRoute('admin_validation') : $this->redirectToRoute('admin_validation_job', ['id' => $ids[0]]);
            } catch (CattoMailException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }
        return $this->render('admin/validation.html.twig', [
            'form' => $form,
            'jobs' => $this->validations->recent(),
            'problem' => $config->problem(),
            'classifications' => self::CLASSIFICATIONS,
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? 422 : 200));
    }

    #[Route('/address-validation/{id}/{page}', name: 'admin_validation_job', defaults: ['page' => 1], requirements: ['id' => '\d+', 'page' => '\d+'], methods: ['GET'])]
    public function show(int $id, int $page, Request $request): Response
    {
        $job = $this->validations->job($id) ?? throw $this->createNotFoundException('Unknown validation job.');
        $class = $request->query->getString('c');
        $class = in_array($class, [...self::CLASSIFICATIONS, 'pending'], true) ? $class : '';
        $pagination = Pagination::fromRequest($request, $page, $this->validations->resultCount($id, $class));
        return $this->render('admin/validation_job.html.twig', [
            'job' => $job,
            'class' => $class,
            'classifications' => self::CLASSIFICATIONS,
            'results' => $this->validations->results($id, $class, $pagination->offset(), $pagination->perPage),
            'pagination' => $pagination,
        ]);
    }

    #[Route('/address-validation/{id}/refresh', name: 'admin_validation_refresh', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function refresh(int $id, AddressValidation $validation): Response
    {
        $job = $this->validations->job($id) ?? throw $this->createNotFoundException('Unknown validation job.');
        try {
            $this->addFlash('info', 'catto-mail: ' . $validation->refresh($job) . '.');
        } catch (CattoMailException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
        return $this->redirectToRoute('admin_validation_job', ['id' => $id]);
    }
}
