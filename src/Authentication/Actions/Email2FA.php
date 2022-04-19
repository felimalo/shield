<?php

namespace Sparks\Shield\Authentication\Actions;

use CodeIgniter\HTTP\IncomingRequest;
use Sparks\Shield\Controllers\LoginController;
use Sparks\Shield\Models\UserIdentityModel;

/**
 * Class Email2FA
 *
 * Sends an email to the user with a code to verify their account.
 */
class Email2FA implements ActionInterface
{
    /**
     * Displays the "Hey we're going to send you an number to your email"
     * message to the user with a prompt to continue.
     *
     * @return mixed
     */
    public function show()
    {
        $user = auth()->user();

        // Delete any previous activation identities
        $identities = new UserIdentityModel();
        $identities->where('user_id', $user->id)
            ->where('type', 'email_2fa')
            ->delete();

        // Create an identity for our 2fa hash
        helper('text');
        $code = random_string('nozero', 6);

        $identities->insert([
            'user_id' => $user->id,
            'type'    => 'email_2fa',
            'secret'  => $code,
            'name'    => 'login',
            'extra'   => lang('Auth.need2FA'),
        ]);

        return view(setting('Auth.views')['action_email_2fa']);
    }

    /**
     * Generates the random number, saves it as a temp identity
     * with the user, and fires off an email to the user with the code,
     * then displays the form to accept the 6 digits
     *
     * @return mixed
     */
    public function handle(IncomingRequest $request)
    {
        $email = $request->getPost('email');
        $user  = auth()->user();

        if (empty($email) || $email !== $user->email) {
            return redirect()->route('auth-action-show')->with('error', lang('Auth.invalidEmail'));
        }

        $identities = new UserIdentityModel();
        $identity   = $identities->where('user_id', $user->id)
            ->where('type', 'email_2fa')
            ->first();

        if (empty($identity)) {
            return redirect()->route('auth-action-show')->with('error', lang('Auth.need2FA'));
        }

        // Send the user an email with the code
        helper('email');
        $email = emailer();
        $email->setFrom(setting('Email.fromEmail'), setting('Email.fromName') ?? '')
            ->setTo($user->email)
            ->setSubject(lang('Auth.email2FASubject'))
            ->setMessage(view(setting('Auth.views')['action_email_2fa_email'], ['code' => $identity->secret]))
            ->send();

        return view(setting('Auth.views')['action_email_2fa_verify']);
    }

    /**
     * Attempts to verify the code the user entered.
     *
     * @return mixed
     */
    public function verify(IncomingRequest $request)
    {
        $token    = $request->getPost('token');
        $user     = auth()->user();
        $identity = $user->getIdentity('email_2fa');

        // Token mismatch? Let them try again...
        if (empty($token) || $token !== $identity->secret) {
            $_SESSION['error'] = lang('Auth.invalid2FAToken');

            return view(setting('Auth.views')['action_email_2fa_verify']);
        }

        // On success - remove the identity and clean up session
        model(UserIdentityModel::class)
            ->where('user_id', $user->id)
            ->where('type', 'email_2fa')
            ->delete();

        // Clean up our session
        session()->remove('auth_action');

        // Get our login redirect url
        $loginController = new LoginController();

        return redirect()->to($loginController->getLoginRedirect($user));
    }
}
