<?php
function handle_api_route(array $segments, string $method): void
{
    $endpoint = $segments[1] ?? '';
    switch ($endpoint) {
        case 'auth':
            $action = $segments[2] ?? '';
            $controller = new AuthController();
            switch ($action) {
                case 'login':
                    $controller->login();
                    return;
                case 'logout':
                    $controller->logout();
                    return;
                case 'me':
                case 'profile':
                    $controller->me();
                    return;
                default:
                    jsonResponse(false, 'Auth endpoint not found', null, 404);
                    return;
            }
        case 'categories':
            $controller = new CategoryController();
            $id = $_GET['id'] ??  null;
            if (isset($segments[3]) && $segments[3] === 'image') {
                $cid = isset($segments[2]) ? (int)$segments[2] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
                if ($cid === null) { jsonResponse(false, 'ID is required', null, 400); return; }
                if ($method === 'POST') {
                    $controller->imageUpload($cid);
                } else {
                    jsonResponse(false, 'Method not allowed', null, 405);
                }
                return;
            }
            switch ($method) {
                case 'GET':
                    if ($id !== null) {
                        $controller->show($id);
                    } else {
                        $controller->index();
                    }
                    return;
                case 'POST':
                    $controller->store();
                    return;
                case 'PUT':
                case 'PATCH':
                    if ($id !== null) {
                        $controller->update($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                case 'DELETE':
                    if ($id !== null) {
                        $controller->destroy($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'brands':
            $controller = new BrandController();
            $id = $_GET['id'] ?? null;
            if (isset($segments[3]) && $segments[3] === 'image') {
                $bid = isset($segments[2]) ? (int)$segments[2] : ($id !== null ? (int)$id : null);
                if ($bid === null) { jsonResponse(false, 'ID is required', null, 400); return; }
                if ($method === 'POST') {
                    $controller->imageUpload($bid);
                } else {
                    jsonResponse(false, 'Method not allowed', null, 405);
                }
                return;
            }
            switch ($method) {
                case 'GET':
                    if ($id !== null) {
                        $controller->show($id);
                    } else {
                        $controller->index();
                    }
                    return;
                case 'POST':
                    $controller->store();
                    return;
                case 'PUT':
                case 'PATCH':
                    if ($id !== null) {
                        $controller->update($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                case 'DELETE':
                    if ($id !== null) {
                        $controller->destroy($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'products':
            $controller = new ProductController();
            $id = $_GET['id'] ?? null;
            if (isset($segments[3]) && $segments[3] === 'specs') {
                $pid = isset($segments[2]) ? (int)$segments[2] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
                if ($pid === null) { jsonResponse(false, 'ID is required', null, 400); return; }
                switch ($method) {
                    case 'GET':
                        $controller->specsIndex($pid);
                        return;
                    case 'POST':
                        $controller->specsCreate($pid);
                        return;
                    default:
                        jsonResponse(false, 'Method not allowed', null, 405);
                        return;
                }
            }
            if (isset($segments[3]) && $segments[3] === 'images') {
                $pid = isset($segments[2]) ? (int)$segments[2] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
                if ($pid === null) { jsonResponse(false, 'ID is required', null, 400); return; }
                switch ($method) {
                    case 'POST':
                        $controller->imagesUpload($pid);
                        return;
                    default:
                        jsonResponse(false, 'Method not allowed', null, 405);
                        return;
                }
            }
            if (isset($segments[3]) && $segments[3] === 'feedback') {
                $pid = isset($segments[2]) ? (int)$segments[2] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
                if ($pid === null) { jsonResponse(false, 'ID is required', null, 400); return; }
                $fb = new FeedbackController();
                switch ($method) {
                    case 'GET':
                        $fb->list($pid);
                        return;
                    case 'POST':
                        $fb->create($pid);
                        return;
                    default:
                        jsonResponse(false, 'Method not allowed', null, 405);
                        return;
                }
            }
            switch ($method) {
                case 'GET':
                    if ($id !== null) {
                        $controller->show($id);
                    } else {
                        $controller->index();
                    }
                    return;
                case 'POST':
                    $controller->store();
                    return;
                case 'PUT':
                case 'PATCH':
                    if ($id !== null) {
                        $controller->update($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                case 'DELETE':
                    if ($id !== null) {
                        $controller->destroy($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'feedback':
            if (isset($segments[2]) && $segments[2] === 'approve') {
                $id = $_GET['id'] ?? (isset($segments[3]) ? (int)$segments[3] : null);
                if ($id === null) { jsonResponse(false, 'ID is required', null, 400); return; }
                $fb = new FeedbackController();
                switch ($method) {
                    case 'PUT':
                    case 'PATCH':
                        $fb->approve($id);
                        return;
                    default:
                        jsonResponse(false, 'Method not allowed', null, 405);
                        return;
                }
            }
            if (isset($segments[2]) && $segments[2] === 'unapprove') {
                $id = $_GET['id'] ?? (isset($segments[3]) ? (int)$segments[3] : null);
                if ($id === null) { jsonResponse(false, 'ID is required', null, 400); return; }
                $fb = new FeedbackController();
                switch ($method) {
                    case 'PUT':
                    case 'PATCH':
                        $fb->unapprove($id);
                        return;
                    default:
                        jsonResponse(false, 'Method not allowed', null, 405);
                        return;
                }
            }
            jsonResponse(false, 'Endpoint not found', null, 404);
            return;
        case 'specs':
            $controller = new ProductController();
            $specId = $_GET['id'] ?? (isset($segments[2]) ? (int)$segments[2] : null);
            if ($specId === null) { jsonResponse(false, 'ID is required', null, 400); return; }
            if (isset($segments[3]) && $segments[3] === 'image') {
                switch ($method) {
                    case 'POST':
                        $controller->specImageUpload($specId);
                        return;
                    default:
                        jsonResponse(false, 'Method not allowed', null, 405);
                        return;
                }
            }
            if (isset($segments[3]) && $segments[3] === 'images') {
                switch ($method) {
                    case 'GET':
                        $controller->specImagesList($specId);
                        return;
                    case 'POST':
                        $controller->specImagesUpload($specId);
                        return;
                    default:
                        jsonResponse(false, 'Method not allowed', null, 405);
                        return;
                }
            }
            switch ($method) {
                case 'PATCH':
                    $controller->specUpdate($specId);
                    return;
                case 'DELETE':
                    $controller->specDestroy($specId);
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'spec-images':
            $controller = new ProductController();
            $imageId = $_GET['id'] ?? (isset($segments[2]) ? (int)$segments[2] : null);
            if ($imageId === null) { jsonResponse(false, 'ID is required', null, 400); return; }
            switch ($method) {
                case 'DELETE':
                    $controller->specImageDestroy($imageId);
                    return;
                case 'PUT':
                case 'PATCH':
                    $controller->specImageSetPrimary($imageId);
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'images':
            $controller = new ProductController();
            $imageId = $_GET['id'] ?? (isset($segments[2]) ? (int)$segments[2] : null);
            if ($imageId === null) { jsonResponse(false, 'ID is required', null, 400); return; }
            switch ($method) {
                case 'DELETE':
                    $controller->imageDestroy($imageId);
                    return;
                case 'PUT':
                case 'PATCH':
                    $controller->imageSetPrimary($imageId);
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'orders':
            $controller = new OrderController();
            $id = $_GET['id'] ?? null;
            if (isset($segments[2]) && $segments[2] === 'status') {
                if ($id === null) { jsonResponse(false, 'ID is required', null, 400); return; }
                switch ($method) {
                    case 'PUT':
                    case 'PATCH':
                        $controller->updateStatus($id);
                        return;
                    default:
                        jsonResponse(false, 'Method not allowed', null, 405);
                        return;
                }
            }
            switch ($method) {
                case 'GET':
                    if ($id !== null) {
                        $controller->show($id);
                    } else {
                        $controller->list();
                    }
                    return;
                case 'POST':
                    $controller->create();
                    return;
                case 'DELETE':
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'dashboard':
            $controller = new DashboardController();
            $action = $segments[2] ?? null;
            switch ($action) {
                case 'summary':
                    if ($method === 'GET') { $controller->summary(); } else { jsonResponse(false, 'Method not allowed', null, 405); }
                    return;
                case 'top-products':
                    if ($method === 'GET') { $controller->topProducts(); } else { jsonResponse(false, 'Method not allowed', null, 405); }
                    return;
                case 'revenue':
                    if ($method === 'GET') { $controller->revenue(); } else { jsonResponse(false, 'Method not allowed', null, 405); }
                    return;
                case 'total-profit':
                    if ($method === 'GET') { $controller->totalProfit(); } else { jsonResponse(false, 'Method not allowed', null, 405); }
                    return;
                case 'expenses-summary':
                    if ($method === 'GET') {
                        $exp = new ExpenseController();
                        $exp->summary();
                    } else { jsonResponse(false, 'Method not allowed', null, 405); }
                    return;
                default:
                    jsonResponse(false, 'Endpoint not found', null, 404);
                    return;
            }
        case 'expenses':
            $controller = new ExpenseController();
            $id = $_GET['id'] ?? null;
            switch ($method) {
                case 'GET':
                    if (isset($segments[2]) && $segments[2] === 'summary') { $controller->summary(); return; }
                    $controller->index();
                    return;
                case 'POST':
                    $controller->create();
                    return;
                case 'PUT':
                case 'PATCH':
                    if ($id !== null) {
                        $controller->update($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                case 'DELETE':
                    if ($id !== null) {
                        $controller->destroy($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'stock':
            $controller = new StockController();
            if (isset($segments[2]) && $segments[2] === 'adjust') {
                if ($method === 'POST') {
                    $controller->adjust();
                } else {
                    jsonResponse(false, 'Method not allowed', null, 405);
                }
                return;
            }
            if ($method === 'GET') {
                $controller->list();
            } else {
                jsonResponse(false, 'Method not allowed', null, 405);
            }
            return;
        case 'users':
            $controller = new UserController();
            $id = $_GET['id'] ??  null;
            switch ($method) {
                case 'GET':
                    if ($id !== null) {
                        $controller->show($id);
                    } else {
                        $controller->index();
                    }
                    return;
                case 'POST':
                    $controller->store();
                    return;
                case 'PUT':
                case 'PATCH':
                    if ($id !== null) {
                        $controller->update($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                case 'DELETE':
                    if ($id !== null) {
                        $controller->destroy($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'subcategories':
            $controller = new SubcategoryController();
            $id = $_GET['id'] ?? null;
            if (isset($segments[3]) && $segments[3] === 'image') {
                $sid = isset($segments[2]) ? (int)$segments[2] : ($id !== null ? (int)$id : null);
                if ($sid === null) { jsonResponse(false, 'ID is required', null, 400); return; }
                if ($method === 'POST') {
                    $controller->imageUpload($sid);
                } else {
                    jsonResponse(false, 'Method not allowed', null, 405);
                }
                return;
            }
            switch ($method) {
                case 'GET':
                    if ($id !== null) {
                        $controller->show($id);
                    } else {
                        $controller->index();
                    }
                    return;
                case 'POST':
                    $controller->store();
                    return;
                case 'PUT':
                case 'PATCH':
                    if ($id !== null) {
                        $controller->update($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                case 'DELETE':
                    if ($id !== null) {
                        $controller->destroy($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'colors':
            $controller = new ColorController();
            $id = $_GET['id'] ?? null;
            switch ($method) {
                case 'GET':
                    if ($id !== null) {
                        $controller->show($id);
                    } else {
                        $controller->index();
                    }
                    return;
                case 'POST':
                    $controller->store();
                    return;
                case 'PUT':
                case 'PATCH':
                    if ($id !== null) {
                        $controller->update($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                case 'DELETE':
                    if ($id !== null) {
                        $controller->destroy($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'sizes':
            $controller = new SizeController();
            $id = $_GET['id'] ?? null;
            switch ($method) {
                case 'GET':
                    if ($id !== null) {
                        $controller->show($id);
                    } else {
                        $controller->index();
                    }
                    return;
                case 'POST':
                    $controller->store();
                    return;
                case 'PUT':
                case 'PATCH':
                    if ($id !== null) {
                        $controller->update($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                case 'DELETE':
                    if ($id !== null) {
                        $controller->destroy($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'promotions':
            $controller = new PromotionController();
            $id = $_GET['id'] ?? null;
            if (isset($segments[3]) && $segments[3] === 'items') {
                $pid = isset($segments[2]) ? (int)$segments[2] : ($id !== null ? (int)$id : null);
                if ($pid === null) { jsonResponse(false, 'ID is required', null, 400); return; }
                switch ($method) {
                    case 'POST':
                        $controller->addItem($pid);
                        return;
                    case 'DELETE':
                        $spec = isset($_GET['spec_id']) ? (int)$_GET['spec_id'] : null;
                        if ($spec === null) { jsonResponse(false, 'spec_id is required', null, 400); return; }
                        $controller->removeItem($pid, $spec);
                        return;
                    default:
                        jsonResponse(false, 'Method not allowed', null, 405);
                        return;
                }
            }
            switch ($method) {
                case 'GET':
                    if ($id !== null) {
                        $controller->show($id);
                    } else {
                        $controller->index();
                    }
                    return;
                case 'POST':
                    $controller->store();
                    return;
                case 'PUT':
                case 'PATCH':
                    if ($id !== null) {
                        $controller->update($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                case 'DELETE':
                    if ($id !== null) {
                        $controller->destroy($id);
                    } else {
                        jsonResponse(false, 'ID is required', null, 400);
                    }
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'cart':
            $controller = new CartController();
            if (isset($segments[2]) && $segments[2] === 'items') {
                $itemId = $_GET['id'] ?? null;
                switch ($method) {
                    case 'POST':
                        $controller->addItem();
                        return;
                    case 'PUT':
                    case 'PATCH':
                        if ($itemId !== null) {
                            $controller->updateItem($itemId);
                        } else {
                            jsonResponse(false, 'ID is required', null, 400);
                        }
                        return;
                    case 'DELETE':
                        if ($itemId !== null) {
                            $controller->removeItem($itemId);
                        } else {
                            jsonResponse(false, 'ID is required', null, 400);
                        }
                        return;
                    default:
                        jsonResponse(false, 'Method not allowed', null, 405);
                        return;
                }
            }
            switch ($method) {
                case 'GET':
                    $sid = $_GET['session_id'] ?? null;
                    if ($sid === null) { jsonResponse(false, 'session_id is required', null, 400); return; }
                    $controller->get($sid);
                    return;
                case 'POST':
                    $controller->create();
                    return;
                default:
                    jsonResponse(false, 'Method not allowed', null, 405);
                    return;
            }
        case 'reports':
            $controller = new ReportsController();
            $action = $segments[2] ?? null;
            switch ($action) {
                case 'sales':
                    if ($method === 'GET') { $controller->sales(); } else { jsonResponse(false, 'Method not allowed', null, 405); }
                    return;
                case 'sales-stats':
                    if ($method === 'GET') { $controller->salesStats(); } else { jsonResponse(false, 'Method not allowed', null, 405); }
                    return;
                default:
                    jsonResponse(false, 'Endpoint not found', null, 404);
                    return;
            }
        default:
            jsonResponse(false, 'Endpoint not found', null, 404);
            return;
    }
}
