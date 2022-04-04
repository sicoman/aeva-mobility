<?php

namespace Qruz\Cab\Domain\Repository\Eloquent\Mutations;

use App\Driver;

use Qruz\Cab\Domain\Models\CabRating;

use Qruz\Cab\Domain\Repository\Eloquent\BaseRepository;

use Illuminate\Database\Eloquent\ModelNotFoundException;

class CabRatingRepository extends BaseRepository
{

    public function __construct(CabRating $model)
    {
        parent::__construct($model);
    }

    public function update(array $args)
    {
        $input = collect($args)->except(['id', 'request_id', 'user_id', 'directive'])->toArray();

        if (array_key_exists('request_id', $args) && $args['request_id'] != null) {
            $rating = $this->model->where('request_id', $args['request_id']);
        } else if (array_key_exists('user_id', $args) && $args['user_id'] != null) {
            $rating = $this->model->where('user_id', $args['user_id']);
        } else {
            throw new \Exception(__('lang.rating_not_found'));
        }

        if (!$rating) {
            throw new \Exception(__('lang.rating_not_found'));
        }
        $rating->update($input);

        $avgs = $this->model->selectRaw('driver_id, AVG(rating) as rating_avg')
            ->whereNotNull('rating')->groupBy('driver_id')->get();

        foreach ($avgs as $avg) {
            Driver::find($avg['driver_id'])->update(['rating' => $avg['rating_avg']]);
        }

        return $rating->get();
    }
}