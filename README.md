# Services Product API

Konfigurasi ini menjalankan API PHP untuk data produk DummyJSON menggunakan Docker Compose.

## Arsitektur

Service yang tersedia:

- **PHP-FPM**: menjalankan API PHP dan koneksi ke MySQL.
- **Caddy**: web server dan reverse proxy ke PHP-FPM.
- **MySQL**: menyimpan data produk pada folder `databases`.
- **phpMyAdmin**: antarmuka web untuk mengelola MySQL.
- **RabbitMQ**: message broker dengan dashboard management.

Source code PHP berada di folder `htdocs` dan document root Caddy mengarah ke folder tersebut.

## Konfigurasi

File `.env` hanya berisi kredensial MySQL:

```dotenv
MYSQL_USER=app
MYSQL_PASSWORD=app_secret
MYSQL_ROOT_PASSWORD=root_secret
```

Ubahlah nilai tersebut sebelum digunakan di lingkungan production.

## Menjalankan Service

Dari folder ini, jalankan:

```bash
docker compose up -d --build
```

Melihat status container:

```bash
docker compose ps
```

Melihat log API PHP:

```bash
docker compose logs -f php
```

Menghentikan service:

```bash
docker compose down
```

Data MySQL tetap tersimpan di folder `databases` saat container dihentikan.

## Import Data DummyJSON

Script seed mengambil seluruh data produk menggunakan endpoint:

```text
https://dummyjson.com/products?limit=0
```

Jalankan seed secara manual:

```bash
docker compose exec php php /var/www/html/seed.php
```

Script aman dijalankan berulang kali karena menggunakan `ON DUPLICATE KEY UPDATE` berdasarkan `id` produk.

API hanya membuat tabel jika belum tersedia. Pengisian data DummyJSON dilakukan melalui script seed secara manual.

## Akses Service

| Service | URL atau host |
| --- | --- |
| API PHP melalui Caddy | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |
| MySQL dari host | `localhost:3306` |
| RabbitMQ AMQP dari host | `localhost:5672` |
| RabbitMQ Management | http://localhost:15672 |

Untuk koneksi antar-container, gunakan host berikut:

- MySQL: `mysql:3306`
- RabbitMQ: `rabbitmq:5672`

## API Produk

Semua endpoint API menggunakan prefix `/products`.

| Method | Endpoint | Keterangan |
| --- | --- | --- |
| `GET` | `/products` | Mengambil daftar produk |
| `GET` | `/products/{id}` | Mengambil satu produk |
| `GET` | `/products/search?q=phone` | Mencari produk |
| `GET` | `/products/category/{category}` | Filter berdasarkan kategori |
| `GET` | `/products/categories` | Mengambil daftar kategori beserta URL |
| `GET` | `/products/category-list` | Mengambil daftar slug kategori |
| `POST` | `/products/add` | Menambahkan produk |
| `PUT` | `/products/{id}` | Mengubah produk |
| `PATCH` | `/products/{id}` | Mengubah sebagian atau seluruh produk |
| `DELETE` | `/products/{id}` | Menghapus produk |

### Query Parameter List

Endpoint `GET /products` mendukung:

- `q`: kata pencarian.
- `limit`: jumlah data, default `30`. Gunakan `0` untuk mengambil semua data.
- `skip`: jumlah data yang dilewati.
- `sortBy`: `id`, `title`, `price`, `rating`, atau `stock`.
- `order`: `asc` atau `desc`.

Contoh:

```text
GET http://localhost:8080/products?limit=10&skip=0&sortBy=price&order=desc
GET http://localhost:8080/products?q=phone
GET http://localhost:8080/products/category/smartphones
```

### Contoh Tambah Produk

```bash
curl -X POST http://localhost:8080/products/add \
  -H 'Content-Type: application/json' \
  -d '{
    "title": "Produk Baru",
    "description": "Deskripsi produk",
    "category": "laptops",
    "price": 899.99,
    "stock": 20,
    "brand": "Contoh"
  }'
```

### Contoh Update Produk

```bash
curl -X PATCH http://localhost:8080/products/1 \
  -H 'Content-Type: application/json' \
  -d '{"price": 799.99, "stock": 25}'
```

### Contoh Hapus Produk

```bash
curl -X DELETE http://localhost:8080/products/1
```

## Login phpMyAdmin

Buka `http://localhost:8081`, lalu gunakan:

- **Server**: `mysql`
- **Username**: nilai `MYSQL_USER` atau `root`
- **Password**: nilai `MYSQL_PASSWORD` atau `MYSQL_ROOT_PASSWORD`

Nama database yang digunakan adalah `app`, dan tabel produk bernama `products`.

## Struktur Folder

```text
services/
├── .env
├── Caddyfile
├── Dockerfile
├── docker-compose.yaml
├── databases/
└── htdocs/
    ├── index.php
    └── seed.php
```
