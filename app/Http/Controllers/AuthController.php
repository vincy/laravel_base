<?php

namespace App\Http\Controllers;
use App\Notifications\SignupActivate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\User;
use Illuminate\Support\Facades\Validator;


class AuthController extends Controller
{


    public function signup( Request $request ) {

        $validator = Validator::make( $request->all(), [
                'first_name'   => 'required|string',
                'last_name'    => 'required|string',
                'email'        => 'required|string|email|confirmed',
                'password'     => 'required|string|min:8|confirmed',
        ]);

        if ( $validator->fails() ) {
            return response()->json( $validator->errors(), 400 );
        }


        $user_data                       = $request->all();
        $user_data[ 'full_name' ]        = $user_data[ 'first_name' ] . " " . $user_data[ 'last_name' ];
        $user_data[ 'password' ]         = bcrypt( $request->password );
        $user_data[ 'activation_token' ] = md5( uniqid() );
        $user_data['active'] = 1;
        $user                            = new User( $user_data );
        $user->save();


        $user->notify( new SignupActivate( ) );
        $user = $user->render();

        return response()->json( $user, 201 );
    }

    /**
     * Login user and create token
     *
     * @param  [string] email
     * @param  [string] password
     * @param  [boolean] remember_me
     * @return [string] access_token
     * @return [string] token_type
     * @return [string] expires_at
     */
    public function login(Request $request)
    {
        $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
                'remember_me' => 'boolean'
        ]);
        $credentials = request(['email', 'password']);
        if(!Auth::attempt($credentials))
            return response()->json([
                    'message' => 'Wrong email and password'
            ], 401);
        $user = $request->user();

        if ( !$user->email_verified_at ) {
            $user->notify( new SignupActivate( ) );

            return response()->json( [ 'message' => "Your email is not confirmed, we resent you a new email" ], 401 );
        } else if($user->active == 0){
            return response()->json([
                    'message' => "Your account is disabled"
            ], 401);
        }

        $tokenResult = $user->createToken('Personal Access Token');
        $token = $tokenResult->token;
        if ($request->remember_me)
            $token->expires_at = Carbon::now()->addWeeks(1);
        $token->save();
        return response()->json([
                'access_token' => $tokenResult->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => Carbon::parse(
                        $tokenResult->token->expires_at
                )->toDateTimeString(),
                'user' => $user->render()
        ]);
    }

    /**
     * Logout user (Revoke the token)
     *
     * @return [string] message
     */
    public function logout(Request $request)
    {
        $request->user()->token()->revoke();
        return response()->json([
                'message' => 'Successfully logged out'
        ]);
    }

    /**
     * Get the authenticated User
     *
     * @return [json] user object
     */
    public function user(Request $request)
    {
        return response()->json($request->user()->render());
    }

    public function changePassword( Request $request ) {
        $user = $request->user();
        $validator = Validator::make( $request->all(), [
                'current_password' => [
                        'required',
                        'string',
                        'min:8',
                        function($attribute, $value, $fail) use ($user) {
                            if (!Hash::check($value, $user->password)) {
                                $fail("The password doesn't match with the current one");
                            }
                        }
                ],
                'new_password'    => 'required|string|confirmed|min:8',
        ] );

        if ( $validator->fails() ) {
            return response()->json( $validator->errors(), 400 );
        }

        $user->password = bcrypt($request->new_password);
        $user->save();
        return response()->json(['message' => "Password Changed"]);

    }


    /**
     * Activate account by clicking email link
     *
     * @param $token
     *
     * @return [json] user object
     */
    public function signupActivate( $token ) {
        $user = User::where( 'activation_token', $token )->first();
        if ( !$user ) {
            return response()->json( [
                    'is_active' => 0,
                    'message' => "Invalid token"
            ], 400 );
        } elseif ($user->email_verified_at !== null) {
            return response()->json( [
                    'is_active' => 1,
                    'message' => "Hi ".$user->first_name."!"
            ], 400 );
        }
        $user->active            = true;
        $user->email_verified_at = date( 'Y-m-d H:i:s' );
        $user->save();

        $user = $user->render();

        return $user;
    }
}