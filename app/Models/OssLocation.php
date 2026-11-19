<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['purpose', 'provider', 'bucket', 'region', 'endpoint', 'public_url', 'credentials', 'user_id'])]
#[Hidden(['credentials'])]
class OssLocation extends Model
{
    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array'];
    }

    public function bucketEndpoint(): bool
    {
        return $this->provider === 'aliyun' && $this->endpoint !== null
            && $this->endpoint !== 'https://s3.oss-'.$this->region.'.aliyuncs.com';
    }
}
