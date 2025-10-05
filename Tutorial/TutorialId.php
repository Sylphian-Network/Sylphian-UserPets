<?php

namespace Sylphian\UserPets\Tutorial;

enum TutorialId: string
{
	case COMPLETE_ACTION = 'complete_action';
	case UPLOAD_PFP      = 'upload_pfp';
	case POST_FIRST_MESSAGE = 'post_first_message';
	case REACT_TO_POST = 'react_to_post';
}
