<?php

namespace App\GraphQL\Mutations;

use App\User;
use App\Jobs\SendOtp;
use App\PartnerTrip;
use App\PartnerUser;
use App\PartnerTripUser;
use App\Mail\DefaultMail;
use Illuminate\Support\Arr;
use Illuminate\Support\Str; 
use App\PartnerTripSchedule; 
use App\Exceptions\CustomException;
use Illuminate\Support\Facades\Mail;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PartnerTripResolver
{
    /**
     * Return a value for the field.
     *
     * @param  null  $rootValue Usually contains the result returned from the parent field. In this case, it is always `null`.
     * @param  mixed[]  $args The arguments that were passed into the field.
     * @param  \Nuwave\Lighthouse\Support\Contracts\GraphQLContext  $context Arbitrary data that is shared between all fields of a single query.
     * @param  \GraphQL\Type\Definition\ResolveInfo  $resolveInfo Information about the query itself, such as the execution state, the field name, path to the field from the root, and more.
     * @return mixed
     */
    public function create($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo)
    {
        $tripInput = $this->tripInput($rootValue, $args, $context, $resolveInfo);
        $newTrip = PartnerTrip::create($tripInput);

        $subscriptionCode = Str::random(4) . 'P' . $newTrip->partner_id . 'T' . $newTrip->id;
        $newTrip->update(['subscription_code' => $subscriptionCode]);
         
        $scheduleInput = $this->scheduleInput($rootValue, $args, $context, $resolveInfo);

        $scheduleInput['trip_id'] = $newTrip->id;
        PartnerTripSchedule::create($scheduleInput);

        return $newTrip;
    }

    public function update($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo)
    {
        $tripInput = $this->tripInput($rootValue, $args, $context, $resolveInfo);
        try {
            $trip = PartnerTrip::findOrFail($args['id']);
            $trip->update($tripInput);
        } catch (ModelNotFoundException $e) {
            throw new CustomException('Trip with the provided ID is not found.');
        }
        
        $scheduleInput = $this->scheduleInput($rootValue, $args, $context, $resolveInfo);
        try {
            $tripSchedule = PartnerTripSchedule::findOrFail($trip->schedule->id);
            $tripSchedule->update($scheduleInput);
        } catch (ModelNotFoundException $e) {
            $scheduleInput['trip_id'] = $trip->id;
            PartnerTripSchedule::create($scheduleInput);
        }
    
        return $trip;
    }

    public function inviteUser($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo)
    {
        $data = [];
        $arr = [];

        foreach($args['user_id'] as $val) {
            $arr['trip_id'] = $args['trip_id'];
            $arr['user_id'] = $val;
            array_push($data, $arr);
        } 

        try {
            PartnerTripUser::insert($data);
        } catch (\Exception $e) {
            throw new CustomException('Each user is allowed to subscribe for a trip once.');
        }

        $users = User::select('phone', 'email')
            ->whereIn('id', $args['user_id'])
            ->get();
        $phones = $users->pluck('phone')->toArray();
        $emails = $users->pluck('email');

        $message = 'Dear valued user, kindly use this code to confirm your subscription: ' . $args['subscription_code'];
        
        Mail::bcc($emails)->send(new DefaultMail($message, "Trip Subscription Code"));
        SendOtp::dispatch(implode(",", $phones), $message); 

        return [
            "status" => true,
            "message" => "Subscription code has been sent."
        ];
    }

    public function subscribeUser($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) 
    {
        try {
            $trip = PartnerTrip::where('subscription_code', $args['subscription_code'])
                ->firstOrFail();
        } catch (\Exception $e) {
            throw new CustomException('The provided subscription code is not valid.');
        }
        
        try {
            $tripUser = PartnerTripUser::where('trip_id', $trip['id'])
                ->where('user_id', $args['user_id'])
                ->firstOrFail();
            if ($tripUser->subscription_verified_at) {
                throw new CustomException('You have already subscribed for this trip.');
            } else {
                $tripUser->update(['subscription_verified_at' => now()]);
            }
        } catch (ModelNotFoundException $e) {
            PartnerTripUser::create([
                'trip_id' => $trip['id'],
                'user_id' => $args['user_id'],
                'subscription_verified_at' => now()
            ]);

            PartnerUser::firstOrCreate([
                'partner_id' => $trip['partner_id'], 
                'user_id' => $args['user_id']
            ], ['employee_id' => 'P' . $trip['partner_id'] . 'U' . $args['user_id']]);
        }
        
        return $trip;
    }

    public function unsubscribeUser($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo)
    {
        try {
            PartnerTripUser::where('trip_id', $args['trip_id'])
                ->whereIn('user_id', $args['user_id'])
                ->delete();
        } catch (\Exception $e) {
            throw new CustomException('Subscription cancellation failed.');
        }
        
        return [
            "status" => true,
            "message" => "Subscription cancellation has done successfully."
        ];
    }

    protected function tripInput($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo)
    {
        return Arr::only($args, ['name', 'partner_id', 'driver_id', 'vehicle_id', 'ride_car_share', 'start_date', 'end_date', 'return_time']);
    }

    protected function scheduleInput($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo)
    {
        return Arr::only($args, ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday']);
    }
}
