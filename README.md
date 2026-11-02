# Biblioteca Institucional IESTP “Pedro P. Díaz”

Aplicación web en PHP para gestionar el catálogo, inventario, usuarios, préstamos y reportes bibliotecarios.

## Requisitos

- PHP 8.1 o superior con las extensiones PDO SQLite habilitadas.
- Apache de XAMPP o el servidor integrado de PHP.

## Instalación inicial segura

1. Clona el repositorio y entra al directorio del proyecto.
2. Crea la primera cuenta de administrador desde la terminal. En PowerShell:

   ```powershell
   $securePassword = Read-Host "Contraseña inicial (mínimo 14 caracteres)" -AsSecureString
   $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
   try {
       $env:SBV_ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
       php setup_admin.php
   } finally {
       Remove-Item Env:SBV_ADMIN_PASSWORD -ErrorAction SilentlyContinue
       [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
   }
   ```

   El comando pedirá nombre y correo. La contraseña no se muestra mientras la escribes. Este script solo funciona desde CLI y solo cuando aún no existe un administrador.
3. Inicia el servidor:

   ```powershell
   php -S localhost:8080
   ```

4. Abre `http://localhost:8080/index.php` y entra seleccionando **Personal**.

También puedes usar Apache en XAMPP y abrir `http://localhost/Biblioteca_Virtual/`.

## Módulos

- Resumen con indicadores y accesos rápidos.
- Catálogo de material bibliográfico y lector de recursos digitales.
- Inventario para registrar, editar y retirar materiales.
- Préstamos, renovaciones y devoluciones.
- Administración de cuentas y perfiles de acceso.
- Registro público limitado a cuentas de estudiante.
- Historial personal, cambio de contraseña, reportes y auditoría.
- Modo oscuro seleccionable, con preferencia guardada en el navegador.

La aplicación utiliza SQLite y guarda la base de datos en `database/biblioteca.sqlite`. Ese archivo y las cargas locales están excluidos de Git porque pueden contener información personal o material institucional. El directorio de base de datos está bloqueado para acceso HTTP directo en Apache mediante `.htaccess`. Conserva una copia de seguridad fuera del repositorio antes de actualizar la instalación.
