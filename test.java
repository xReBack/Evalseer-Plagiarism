import java.util.Scanner;

public class test {
    public static void main(String args[]){
        Scanner input = new Scanner(System.in);
        int i, fact = 1;
        int number = input.nextInt();
        for(i=1;i<=number;i++){    
             fact=fact*i;    
        }    
        System.out.print(fact);
    }  
    
}
