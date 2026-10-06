<?php

declare(strict_types=1);

namespace App\Controller;

use App\CattoMail\GlobalOptOut;
use App\Form\FormErrors;
use App\Form\Model\ProfileDetails;
use App\Form\Model\ProfileImageUpload;
use App\Form\Type\ProfileImageType;
use App\Form\Type\ProfileType;
use App\Log\MessageLog;
use App\Repository\MembershipRepository;
use App\Repository\SubscriberImageRepository;
use App\Repository\SubscriberRepository;
use App\Security\AuthenticationPrompt;
use App\Security\Csrf;
use App\Security\SubscriberUser;
use App\Subscriber\ProfileImage;
use App\Subscriber\ProfileImages;
use App\Subscriber\ProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * The signed-in subscriber's own pages. Anonymous visitors are sent to the
 * login page, as in v5.
 */
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly MembershipRepository $memberships,
        private readonly ProfileImages $profileImages,
    ) {
    }

    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function profile(#[CurrentUser] ?SubscriberUser $user): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        return $this->redirectToRoute('profile_subscriber', ['token' => $user->uuid]);
    }

    /** Opened from links in mail: the profile if it is yours, else verify ownership first. */
    #[Route('/profile/subscriber/{token}', name: 'profile_subscriber', methods: ['GET'])]
    public function subscriberProfile(string $token, #[CurrentUser] ?SubscriberUser $user, AuthenticationPrompt $prompt, GlobalOptOut $optOut): Response
    {
        if ($user === null || strtolower(trim($token)) !== $user->uuid) {
            $context = $prompt->for(strtolower(trim($token)), 'profile') ?? throw $this->createNotFoundException('Unknown subscriber.');
            return $this->render('auth/prompt.html.twig', $context);
        }
        return $this->render('profile/show.html.twig', [
            'profile' => $this->subscribers->profile($user->id),
            'has_image' => $this->profileImages->has($user->id),
            'memberships' => $this->memberships->forSubscriber($user->id),
            'global_optout' => $optOut->current($user->id),
            'global_optout_available' => $optOut->isAvailable(),
        ]);
    }

    #[Route('/edit-profile', name: 'profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, #[CurrentUser] ?SubscriberUser $user, ProfileService $profiles): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        $profile = (array) $this->subscribers->profile($user->id);
        $form = $this->createForm(ProfileType::class, ProfileDetails::fromProfile($profile));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ProfileDetails $details */
            $details = $form->getData();
            $profiles->update($user, $details->input());
            $this->addFlash('info', 'Your profile has been updated.');
            return $this->redirectToRoute('profile_edit');
        }
        return $this->editPage($user, $form, $this->imageForm(), $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * The signed-in subscriber's own picture (never anyone else's: there is
     * no picture-by-id address). Without one, the placeholder.
     */
    #[Route('/profile/image', name: 'profile_image', methods: ['GET'])]
    public function image(Request $request, #[CurrentUser] ?SubscriberUser $user, SubscriberImageRepository $images, ProfileImages $profileImages): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        $image = $images->find($user->id);
        if ($image === null) {
            return $this->redirect($profileImages->placeholderUrl());
        }
        $response = new Response($image['png'], Response::HTTP_OK, ['Content-Type' => 'image/png', 'Content-Disposition' => 'inline']);
        // Pages link to it with ?v=<version>, which changes with every new picture: that address can be kept.
        $response->headers->set('Cache-Control', $request->query->getString('v') === $images->version($user->id)
            ? 'private, max-age=31536000, immutable'
            : 'private, no-cache');
        return $response;
    }

    #[Route('/profile/image', name: 'profile_image_save', methods: ['POST'])]
    public function saveImage(Request $request, #[CurrentUser] ?SubscriberUser $user, ProfileImages $profileImages): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        $form = $this->imageForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ProfileImageUpload $upload */
            $upload = $form->getData();
            try {
                $profileImages->save($user->id, $upload->editedImage(), $upload->uploadedBytes());
                $this->addFlash('info', 'Your profile picture has been saved.');
                return $this->redirectToRoute('profile_edit');
            } catch (\InvalidArgumentException $e) {
                FormErrors::attach($form, $e);
            }
        }
        $details = $this->createForm(ProfileType::class, ProfileDetails::fromProfile((array) $this->subscribers->profile($user->id)));
        return $this->editPage($user, $details, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/profile/image/remove', name: 'profile_image_remove', methods: ['POST'])]
    #[IsCsrfTokenValid(Csrf::TOKEN_ID, tokenKey: Csrf::FIELD)]
    public function removeImage(#[CurrentUser] ?SubscriberUser $user, ProfileImages $profileImages): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        $this->addFlash('info', $profileImages->remove($user->id) ? 'Your profile picture has been removed.' : 'You have no profile picture.');
        return $this->redirectToRoute('profile_edit');
    }

    private function imageForm(): FormInterface
    {
        return $this->createForm(ProfileImageType::class, new ProfileImageUpload(), ['action' => $this->generateUrl('profile_image_save')]);
    }

    private function editPage(SubscriberUser $user, FormInterface $form, FormInterface $imageForm, int $status): Response
    {
        return $this->render('profile/edit.html.twig', [
            'form' => $form,
            'image_form' => $imageForm,
            'email' => $user->email,
            'has_image' => $this->profileImages->has($user->id),
            'image_size' => ProfileImage::SIZE,
        ], new Response(status: $status));
    }

    #[Route('/my/messages', name: 'profile_messages', methods: ['GET'])]
    public function messages(#[CurrentUser] ?SubscriberUser $user, MessageLog $messageLog): Response
    {
        if ($user === null) {
            return $this->redirectToRoute('login');
        }
        return $this->render('profile/messages.html.twig', ['messages' => $messageLog->history($user->uuid)]);
    }
}
